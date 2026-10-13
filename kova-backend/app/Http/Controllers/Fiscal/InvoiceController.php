<?php

namespace App\Http\Controllers\Fiscal;

use App\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Models\FiscalOutbox;
use App\Models\Invoice;
use App\Services\Branches\BranchAccessService;
use App\Services\Fiscal\FiscalNumberFormatter;
use App\Services\Fiscal\InvoiceDocumentStore;
use App\Services\Fiscal\InvoicePdfRenderer;
use App\Services\Fiscal\InvoiceService;
use App\Tenancy\CurrentTenant;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    public function index(
        Request $request,
        CurrentTenant $currentTenant,
        InvoiceService $service,
        BranchAccessService $access
    ): JsonResponse {
        abort_unless(
            $request->user()?->can(
                PermissionName::FiscalView->value
            ),
            403
        );

        $branchIds = $access->historicalBranchIds(
            $request->user(),
            $currentTenant->get(),
            PermissionName::FiscalView->value
        );

        $invoices = Invoice::query()
            ->whereHas(
                'order',
                fn($q) =>
                $q->whereIn(
                    'branch_id',
                    $branchIds
                )
            )
            ->latest()
            ->paginate(
                min(
                    100,
                    max(
                        1,
                        (int) $request->input(
                            'limit',
                            20
                        )
                    )
                )
            );

        return response()->json([
            'data' =>
            collect(
                $invoices->items()
            )->map(
                fn($invoice) => [
                    ...$service->publicInvoice(
                        $invoice
                    ),
                    ...$this->downloadUrls(
                        $invoice
                    ),
                ]
            ),

            'meta' => [
                'current_page' =>
                $invoices->currentPage(),

                'last_page' =>
                $invoices->lastPage(),
            ],
        ]);
    }

    public function show(
        Request $request,
        Invoice $invoice,
        InvoiceService $service,
        CurrentTenant $currentTenant,
        BranchAccessService $access
    ): JsonResponse {
        abort_unless(
            $request->user()?->can(
                PermissionName::FiscalView->value
            ),
            403
        );

        $invoice->loadMissing(
            'order.branch'
        );

        $access->assertHistory(
            $request->user(),
            $currentTenant->get(),
            $invoice->order->branch,
            PermissionName::FiscalView->value
        );

        return response()->json([
            'data' => [
                ...$service->publicInvoice(
                    $invoice
                ),

                'items' =>
                $invoice
                    ->items()
                    ->get()
                    ->map(
                        fn($item) => [
                            'description' =>
                            $item->description,

                            'quantity' =>
                            $item->quantity,

                            'unit_value' =>
                            $item->unit_value,

                            'amount' =>
                            $item->amount,

                            'product_service_code' =>
                            $item->product_service_code,

                            'unit_code' =>
                            $item->unit_code,

                            'tax_object_code' =>
                            $item->tax_object_code,

                            'tax_amount' =>
                            $item->tax_amount,
                        ]
                    ),

                ...$this->downloadUrls(
                    $invoice
                ),
            ],
        ]);
    }

    public function cancel(
        Request $request,
        Invoice $invoice,
        CurrentTenant $currentTenant,
        BranchAccessService $access
    ): JsonResponse {
        abort_unless(
            $request->user()?->can(
                PermissionName::FiscalManage->value
            ),
            403
        );

        $invoice->loadMissing(
            'order.branch'
        );

        $access->assertHistory(
            $request->user(),
            $currentTenant->get(),
            $invoice->order->branch,
            PermissionName::FiscalManage->value
        );

        $data = $request->validate([
            'reason' => [
                'required',
                'in:01,02,03,04',
            ],

            'replacement_uuid' => [
                'nullable',
                'uuid',
                'required_if:reason,01',
            ],
        ]);

        if (
            $invoice->status !== 'stamped'
            ||
            ! $invoice->fiscal_uuid
        ) {
            throw new DomainException(
                'Sólo se puede cancelar un CFDI timbrado.'
            );
        }

        FiscalOutbox::query()
            ->firstOrCreate(
                [
                    'operation_key' =>
                    'cancel:'
                        . $invoice->public_id,
                ],
                [
                    'invoice_id' =>
                    $invoice->id,

                    'kind' =>
                    'cancel',

                    'payload' =>
                    $data,

                    'available_at' =>
                    now(),
                ]
            );

        $invoice->update([
            'status' =>
            'cancellation_pending',
        ]);

        return response()->json(
            [
                'message' =>
                'La cancelación quedó en proceso.',
            ],
            202
        );
    }

    public function download(
        Request $request,
        Invoice $invoice,
        InvoiceDocumentStore $documents,
        FiscalNumberFormatter $numberFormatter,
        CurrentTenant $currentTenant,
        BranchAccessService $access
    ): Response {
        abort_unless(
            $request->user()?->can(
                PermissionName::FiscalView->value
            ),
            403
        );

        $invoice->loadMissing(
            'order.branch'
        );

        $access->assertHistory(
            $request->user(),
            $currentTenant->get(),
            $invoice->order->branch,
            PermissionName::FiscalView->value
        );

        abort_unless(
            $invoice->stamped_xml_path,
            404
        );

        $filename =
            $numberFormatter->format(
                $invoice->series,
                (int) $invoice->folio
            )
            . '.xml';

        return response(
            $documents->get(
                $invoice->stamped_xml_path
            ),
            200,
            [
                'Content-Type' =>
                'application/xml',

                'Content-Disposition' =>
                'attachment; filename="'
                    . $filename
                    . '"',
            ]
        );
    }

    public function downloadPdf(
        Request $request,
        Invoice $invoice,
        InvoiceDocumentStore $documents,
        InvoicePdfRenderer $pdf,
        FiscalNumberFormatter $numberFormatter,
        CurrentTenant $currentTenant,
        BranchAccessService $access
    ): Response {
        abort_unless(
            $request->user()?->can(
                PermissionName::FiscalView->value
            ),
            403
        );

        $invoice->loadMissing(
            'order.branch'
        );

        $access->assertHistory(
            $request->user(),
            $currentTenant->get(),
            $invoice->order->branch,
            PermissionName::FiscalView->value
        );

        /*
         * El PDF es un documento derivado.
         *
         * Si desapareció o nunca fue generado,
         * lo reconstruimos usando exclusivamente
         * el XML timbrado que Kova ya conserva.
         *
         * Aquí NO existe ninguna llamada al PAC.
         */
        if (! $invoice->pdf_path) {
            abort_unless(
                $invoice->stamped_xml_path,
                404
            );

            $xml =
                $documents->get(
                    $invoice->stamped_xml_path
                );

            $pdfPath =
                $documents->put(
                    $invoice,
                    'invoice',
                    $pdf->render(
                        $xml
                    )
                );

            $invoice->update([
                'pdf_path' =>
                $pdfPath,
            ]);

            $invoice->refresh();
        }

        $filename =
            $numberFormatter->format(
                $invoice->series,
                (int) $invoice->folio
            )
            . '.pdf';

        return response(
            $documents->get(
                $invoice->pdf_path
            ),
            200,
            [
                'Content-Type' =>
                'application/pdf',

                'Content-Disposition' =>
                'attachment; filename="'
                    . $filename
                    . '"',
            ]
        );
    }

    private function downloadUrls(
        Invoice $invoice
    ): array {
        $xml =
            $invoice->stamped_xml_path
            ? URL::temporarySignedRoute(
                'fiscal.invoice.download',
                now()->addMinutes(10),
                [
                    'invoice' =>
                    $invoice->public_id,
                ]
            )
            : null;

        /*
         * Aunque todavía no exista pdf_path,
         * el XML timbrado permite reconstruir
         * el PDF localmente.
         */
        $canProvidePdf =
            $invoice->pdf_path
            ||
            $invoice->stamped_xml_path;

        $pdf =
            $canProvidePdf
            ? URL::temporarySignedRoute(
                'fiscal.invoice.download.pdf',
                now()->addMinutes(10),
                [
                    'invoice' =>
                    $invoice->public_id,
                ]
            )
            : null;

        return [
            'download_url' =>
            $xml,

            'xml_download_url' =>
            $xml,

            'pdf_download_url' =>
            $pdf,
        ];
    }
}
