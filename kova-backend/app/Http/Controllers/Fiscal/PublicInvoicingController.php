<?php

namespace App\Http\Controllers\Fiscal;

use App\Http\Controllers\Controller;
use App\Services\Fiscal\FiscalTicketService;
use App\Services\Fiscal\InvoiceDocumentStore;
use App\Services\Fiscal\InvoiceService;
use App\Services\Fiscal\SatCatalogService;
use App\Tenancy\CurrentTenant;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicInvoicingController extends Controller
{
    /**
     * Resuelve el código corto impreso en el ticket.
     *
     * Ejemplos válidos:
     *
     * K7PT4M9X2QW8
     * K7PT-4M9X-2QW8
     * K7PT 4M9X 2QW8
     *
     * El código corto solamente sirve para localizar
     * el Ticket. El acceso directo continúa utilizando
     * el token largo y seguro.
     */
    public function resolveCode(
        Request $request,
        InvoiceService $invoices,
        FiscalTicketService $fiscalTickets,
        CurrentTenant $currentTenant,
    ): JsonResponse {
        $data =
            $request->validate([
                'access_code' => [
                    'required',
                    'string',
                    'max:20',
                    'regex:/^[2-9A-HJ-KM-NP-Z]{4}[ -]?[2-9A-HJ-KM-NP-Z]{4}[ -]?[2-9A-HJ-KM-NP-Z]{4}$/i',
                ],
            ]);

        [
            $tenant,
            $ticket,
        ] =
            $invoices
            ->resolveTicketByAccessCode(
                $data['access_code']
            );

        /*
         * Ejecutamos dentro del tenant correspondiente.
         *
         * El código público no necesita conocer
         * previamente a qué tenant pertenece.
         */
        $url =
            $currentTenant->run(
                $tenant,
                fn(): string =>
                $fiscalTickets
                    ->publicUrl(
                        $ticket
                    )
            );

        return response()->json([
            'data' => [
                'url' =>
                $url,
            ],
        ]);
    }

    public function show(
        string $token,
        InvoiceService $invoices,
        CurrentTenant $currentTenant,
    ): JsonResponse {
        [
            $tenant,
            $ticket,
        ] =
            $invoices
            ->resolveTicket(
                $token
            );

        return $currentTenant->run(
            $tenant,
            function () use (
                $ticket,
                $invoices,
                $token,
            ) {
                $ticket->load(
                    'invoice'
                );

                return response()->json([
                    'data' =>
                    $invoices
                        ->publicTicket(
                            $ticket,
                            $token
                        ),
                ]);
            }
        );
    }

    public function store(
        Request $request,
        string $token,
        InvoiceService $invoices,
        CurrentTenant $currentTenant,
        SatCatalogService $catalogs,
    ): JsonResponse {
        $idempotencyKey =
            trim(
                (string) $request->header(
                    'Idempotency-Key'
                )
            );

        if (
            $idempotencyKey === ''
            ||
            strlen(
                $idempotencyKey
            ) > 200
        ) {
            return response()->json(
                [
                    'message' =>
                    'Se requiere una clave de idempotencia válida.',
                ],
                422
            );
        }

        $data =
            $request->validate([
                'rfc' => [
                    'required',
                    'string',
                    'regex:/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/i',
                ],

                'legal_name' => [
                    'required',
                    'string',
                    'max:254',
                ],

                'tax_regime_code' => [
                    'required',
                    'string',
                    'size:3',
                ],

                'postal_code' => [
                    'required',
                    'digits:5',
                ],

                'email' => [
                    'required',
                    'email:rfc',
                    'max:254',
                ],

                'cfdi_use_code' => [
                    'required',
                    'string',
                    'max:4',
                ],
            ]);

        [
            $tenant,
            $ticket,
        ] =
            $invoices
            ->resolveTicket(
                $token
            );

        $catalogs->assertReceiver(
            $data
        );

        try {
            $invoice =
                $currentTenant->run(
                    $tenant,
                    fn() =>
                    $invoices
                        ->request(
                            $tenant,
                            $ticket,
                            $data,
                            $idempotencyKey
                        )
                );
        } catch (
            DomainException $exception
        ) {
            return response()->json(
                [
                    'message' =>
                    'El negocio no puede emitir facturas en este momento.',
                ],
                409
            );
        }

        return response()->json(
            [
                'data' =>
                $invoices
                    ->publicInvoice(
                        $invoice,
                        $token
                    ),
            ],
            202
        );
    }

    public function status(
        string $token,
        InvoiceService $invoices,
        CurrentTenant $currentTenant,
    ): JsonResponse {
        return $this->show(
            $token,
            $invoices,
            $currentTenant
        );
    }

    public function catalogs(
        Request $request,
        string $token,
        InvoiceService $invoices,
        SatCatalogService $catalogs,
    ): JsonResponse {
        [,
            $ticket,
        ] =
            $invoices
            ->resolveTicket(
                $token
            );

        abort_unless(
            $ticket
                ->expires_at
                ->isFuture(),
            404
        );

        $data =
            $request->validate([
                'rfc' => [
                    'required',
                    'string',
                    'max:13',
                ],

                'tax_regime_code' => [
                    'nullable',
                    'string',
                    'size:3',
                ],
            ]);

        return response()->json([
            'data' => [
                'tax_regimes' =>
                $catalogs
                    ->regimesForReceiver(
                        $data['rfc']
                    ),

                'cfdi_uses' =>
                isset(
                    $data['tax_regime_code']
                )
                    ? $catalogs
                    ->cfdiUses(
                        $data['rfc'],
                        $data['tax_regime_code']
                    )
                    : [],
            ],

            'meta' => [
                'catalog_version' =>
                $catalogs
                    ->versionName(),
            ],
        ]);
    }

    public function download(
        string $token,
        string $format,
        InvoiceService $invoices,
        InvoiceDocumentStore $documents,
        CurrentTenant $currentTenant,
    ): Response {
        abort_unless(
            in_array(
                $format,
                [
                    'xml',
                    'pdf',
                ],
                true
            ),
            404
        );

        [
            $tenant,
            $ticket,
        ] =
            $invoices
            ->resolveTicket(
                $token
            );

        return $currentTenant->run(
            $tenant,
            function () use (
                $ticket,
                $format,
                $documents,
            ) {
                $invoice =
                    $ticket
                    ->invoice;

                abort_unless(
                    $invoice
                        &&
                        $invoice->status ===
                        'stamped',
                    404
                );

                $path =
                    $format === 'pdf'
                    ? $invoice
                    ->pdf_path
                    : $invoice
                    ->stamped_xml_path;

                abort_unless(
                    $path,
                    404
                );

                return response(
                    $documents->get(
                        $path
                    ),
                    200,
                    [
                        'Content-Type' =>
                        $format === 'pdf'
                            ? 'application/pdf'
                            : 'application/xml',

                        'Content-Disposition' =>
                        'attachment; filename="'
                            . $invoice->series
                            . '-'
                            . $invoice->folio
                            . '.'
                            . $format
                            . '"',

                        'Cache-Control' =>
                        'private, no-store',
                    ]
                );
            }
        );
    }
}
