<?php

namespace App\Services\Fiscal;


use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Models\FiscalOutbox;
use App\Models\FiscalReceiverProfile;
use App\Models\FiscalSeries;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantFiscalProfile;
use App\Models\Ticket;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

final class InvoiceService
{
    public function __construct(
        private readonly StampCapacityService $capacity,
        private readonly SatCatalogService $catalogs,
        private readonly BranchFiscalSettingsService $branchFiscal,
    ) {}

    /**
     * Resuelve un ticket a partir del token largo
     * utilizado por la URL pública y el código QR.
     *
     * El token continúa siendo la credencial fuerte
     * de acceso directo.
     *
     * @return array{0: Tenant, 1: Ticket}
     */
    public function resolveTicket(string $token): array
    {
        $ticket = Ticket::withoutGlobalScopes()
            ->where(
                'token_hash',
                hash(
                    'sha256',
                    $token
                )
            )
            ->with([
                'tenant',
                'order.items',
            ])
            ->first();

        if (! $ticket) {
            throw new NotFoundHttpException(
                'El enlace de facturación no es válido o ya no está disponible.'
            );
        }

        return [
            $ticket->tenant,
            $ticket,
        ];
    }

    /**
     * Resuelve un ticket utilizando el código
     * corto impreso para captura manual.
     *
     * Acepta indistintamente:
     *
     * K7PT4M9X2QW8
     * K7PT-4M9X-2QW8
     * k7pt-4m9x-2qw8
     *
     * El código se normaliza antes de calcular
     * el mismo SHA-256 almacenado en tickets.
     *
     * @return array{0: Tenant, 1: Ticket}
     */
    public function resolveTicketByAccessCode(
        string $accessCode,
    ): array {
        $normalizedCode =
            $this->normalizeAccessCode(
                $accessCode
            );

        $ticket = Ticket::withoutGlobalScopes()
            ->where(
                'access_code_hash',
                hash(
                    'sha256',
                    $normalizedCode
                )
            )
            ->with([
                'tenant',
                'order.items',
            ])
            ->first();

        if (! $ticket) {
            throw new NotFoundHttpException(
                'No encontramos un ticket disponible con ese código.'
            );
        }

        return [
            $ticket->tenant,
            $ticket,
        ];
    }

    public function publicTicket(Ticket $ticket, ?string $token = null): array
    {
        $order = $ticket->order;

        return [
            'business' => $ticket->tenant->name,
            'folio' => $order->folio,
            'paid_at' => $order->paid_at,
            'tax_base_total' => $order->tax_base_total,
            'tax_total' => $order->tax_total,
            'total' => $order->total,
            'currency' => strtoupper($ticket->tenant->currency ?: 'mxn'),
            'expires_at' => $ticket->expires_at,
            'eligible' => $ticket->fiscal_eligible && $ticket->expires_at->isFuture(),
            'items' => $order->items->map(
                fn($item): array => [
                    'name' => $item->product_name_snapshot,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => $item->unit_price_snapshot,
                    'line_total' => $item->line_total,
                    'tax_base' => $item->tax_base,
                    'tax_amount' => $item->tax_amount,
                    'tax_name' => $item->tax_name_snapshot,
                    'tax_code' => $item->tax_code_snapshot,
                    'tax_factor_type' => $item->tax_factor_type_snapshot,
                    'tax_rate' => $item->tax_rate_snapshot,
                ]
            )->values()->all(),
            'invoice' => $ticket->invoice
                ? $this->publicInvoice($ticket->invoice, $token)
                : null,
        ];
    }

    public function request(
        Tenant $tenant,
        Ticket $ticket,
        array $receiver,
        string $idempotencyKey,
    ): Invoice {
        return DB::transaction(function () use ($tenant, $ticket, $receiver, $idempotencyKey) {
            $hash = hash(
                'sha256',
                $tenant->public_id . ':' . $ticket->public_id . ':' . $idempotencyKey
            );

            $invoice = DB::transaction(function () use ($tenant, $ticket, $receiver, $hash) {
                $locked = Ticket::query()
                    ->whereKey($ticket->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $locked->fiscal_eligible || $locked->expires_at->isPast()) {
                    throw new DomainException(
                        'Este ticket no está disponible para facturación.'
                    );
                }

                /*
                 * Si ya existe una factura no cancelada
                 * para este ticket, reutilizamos esa
                 * solicitud y evitamos duplicados.
                 */
                $existing = Invoice::query()
                    ->where('ticket_id', $locked->id)
                    ->whereNotIn('status', ['cancelled'])
                    ->first();

                if ($existing) {
                    return $existing;
                }

                /*
                 * Si hubo una factura cancelada,
                 * la siguiente podrá declararla
                 * como factura reemplazada.
                 */
                $replacedInvoiceId = Invoice::query()
                    ->where('ticket_id', $locked->id)
                    ->where('status', 'cancelled')
                    ->latest('id')
                    ->value('id');

                /*
                 * El tenant debe contar actualmente
                 * con configuración fiscal lista y
                 * certificado vigente para poder
                 * emitir el CFDI.
                 */
                $profile = TenantFiscalProfile::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('status', 'ready')
                    ->first();

                if (
                    ! $profile ||
                    ! $profile->certificates()
                        ->where('status', 'active')
                        ->where('valid_until', '>', now())
                        ->exists()
                ) {
                    throw new DomainException(
                        'El negocio no puede emitir facturas en este momento.'
                    );
                }

                $effectiveAt = now();

                $this->catalogs->assertProfile(
                    $profile->only([
                        'rfc',
                        'tax_regime_code',
                        'postal_code',
                        'default_product_service_code',
                        'default_unit_code',
                        'default_tax_object_code',
                    ]),
                    $effectiveAt
                );

                $this->catalogs->assertReceiver($receiver, $effectiveAt);

                $order = Order::query()
                    ->whereKey($locked->order_id)
                    ->with(['items.product', 'branch'])
                    ->firstOrFail();

                $branch = $order->branch;
                if (! $branch || ! $branch->postal_code || ! $branch->timezone) {
                    throw new DomainException('La sucursal de la venta no tiene ubicación fiscal completa.');
                }

                $savedReceiver = FiscalReceiverProfile::query()->updateOrCreate(
                    [
                        'rfc' => strtoupper($receiver['rfc']),
                    ],
                    [
                        'legal_name' => mb_strtoupper(trim($receiver['legal_name'])),
                        'tax_regime_code' => $receiver['tax_regime_code'],
                        'postal_code' => $receiver['postal_code'],
                        'email' => strtolower($receiver['email']),
                    ]
                );

                $series = $this->branchFiscal->effectiveSeries($tenant, $branch, $profile->default_series);

                $series = FiscalSeries::query()
                    ->whereKey($series->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $folio = $series->next_folio;
                $series->increment('next_folio');

                $invoice = Invoice::query()->create([
                    'order_id' => $order->id,
                    'ticket_id' => $locked->id,
                    'replaces_invoice_id' => $replacedInvoiceId,
                    'fiscal_receiver_profile_id' => $savedReceiver->id,
                    'provider' => config('fiscal.provider'),
                    'series' => $series->series,
                    'folio' => $folio,
                    'issuer_snapshot' => [
                        'rfc' => $profile->rfc,
                        'legal_name' => $profile->legal_name,
                        'tax_regime_code' => $profile->tax_regime_code,
                        'postal_code' => $profile->postal_code,
                    ],
                    'place_of_issue_postal_code' => $branch->postal_code,
                    'issued_timezone' => $branch->timezone,
                    'branch_snapshot' => [
                        'public_id' => $branch->public_id,
                        'code' => $branch->code,
                        'name' => $branch->name,
                        'postal_code' => $branch->postal_code,
                    ],
                    'receiver_snapshot' => [
                        'rfc' => strtoupper($receiver['rfc']),
                        'legal_name' => mb_strtoupper(trim($receiver['legal_name'])),
                        'tax_regime_code' => $receiver['tax_regime_code'],
                        'postal_code' => $receiver['postal_code'],
                        'email' => strtolower($receiver['email']),
                        'cfdi_use_code' => $receiver['cfdi_use_code'],
                    ],
                    'subtotal' => $order->tax_base_total,
                    'tax_total' => $order->tax_total,
                    'total' => $order->total,
                    'currency' => 'MXN',
                    'status' => 'queued',
                    'idempotency_key_hash' => $hash,
                ]);

                foreach ($order->items as $item) {
                    $product = $item->product;

                    $this->catalogs->assertProduct(
                        [
                            'sat_product_service_code' => $product?->sat_product_service_code,
                            'sat_unit_code' => $product?->sat_unit_code,
                            'sat_tax_object_code' => $product?->sat_tax_object_code,
                        ],
                        $effectiveAt
                    );

                    $taxObject = $product?->sat_tax_object_code
                        ?? $profile->default_tax_object_code;

                    $unitCode = $product?->sat_unit_code
                        ?? $profile->default_unit_code;

                    $quantity = max(1, (float) $item->quantity);
                    $taxBase = (float) $item->tax_base;
                    $taxRate = (float) $item->tax_rate_snapshot;

                    /*
     * CFDI / MXN:
     *
     * ValorUnitario debe expresarse con los
     * decimales soportados por la moneda.
     */
                    $unitValue = round(
                        $taxBase / $quantity,
                        2
                    );

                    /*
     * El POS conserva impuestos monetarios
     * redondeados a 2 decimales.
     *
     * Para el traslado a nivel concepto,
     * reconstruimos la precisión fiscal a
     * partir de Base × Tasa.
     */
                    $taxAmount =
                        $taxObject === '02'
                        && $item->tax_factor_type_snapshot !== 'exempt'
                        ? round(
                            $taxBase * ($taxRate / 100),
                            6
                        )
                        : 0.0;

                    $invoice->items()->create([
                        'order_item_id' => $item->id,
                        'description' => $item->product_name_snapshot,
                        'quantity' => $item->quantity,
                        'unit_value' => $unitValue,
                        'amount' => $item->tax_base,
                        'product_service_code' => $product?->sat_product_service_code
                            ?? $profile->default_product_service_code,
                        'unit_code' => $unitCode,
                        'unit_name' => $this->catalogs->resolve(
                            'sat_units',
                            $unitCode,
                            $effectiveAt
                        )['description'],
                        'tax_object_code' => $taxObject,
                        'tax_code' => $item->tax_code_snapshot === 'iva'
                            ? '002'
                            : $item->tax_code_snapshot,
                        'tax_factor_type' => $item->tax_factor_type_snapshot,
                        'tax_rate' => $item->tax_rate_snapshot,
                        'tax_base' => $item->tax_base,
                        'tax_amount' => $taxAmount,
                    ]);
                }

                return $invoice;
            }, 3);

            if (! $invoice->reservation()->exists()) {
                $this->capacity->reserve($tenant, $invoice);
            }

            FiscalOutbox::query()->firstOrCreate(
                [
                    'operation_key' => 'stamp:' . $invoice->public_id,
                ],
                [
                    'invoice_id' => $invoice->id,
                    'kind' => 'stamp',
                    'payload' => [],
                    'available_at' => now(),
                ]
            );

            return $invoice->refresh();
        }, 3);
    }

    public function publicInvoice(
        Invoice $invoice,
        ?string $publicTicketToken = null,
    ): array {
        $downloads = null;

        if ($publicTicketToken && $invoice->status === 'stamped') {
            $route = fn(string $format) => URL::temporarySignedRoute(
                'public.fiscal.factura.download',
                now()->addMinutes(10),
                [
                    'token' => $publicTicketToken,
                    'format' => $format,
                ],
            );

            $downloads = [
                'xml' => $invoice->stamped_xml_path ? $route('xml') : null,
                'pdf' => $invoice->pdf_path ? $route('pdf') : null,
            ];
        }

        return [
            'id' => $invoice->public_id,
            'series' => $invoice->series,
            'folio' => $invoice->folio,
            'uuid' => $invoice->fiscal_uuid,
            'status' => $invoice->status,
            'total' => $invoice->total,
            'issued_at' => $invoice->issued_at,
            'downloads' => $downloads,
        ];
    }

    /**
     * Normaliza únicamente la presentación
     * del código corto.
     *
     * No intentamos corregir caracteres
     * diferentes ni transformar 0/O o 1/I.
     *
     * K7PT-4M9X-2QW8
     *
     * se convierte en:
     *
     * K7PT4M9X2QW8
     */
    private function normalizeAccessCode(string $accessCode): string
    {
        $normalized = strtoupper(trim($accessCode));

        return preg_replace('/[\s-]+/', '', $normalized) ?? '';
    }
}
