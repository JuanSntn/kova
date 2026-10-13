<?php

namespace App\Services\Tickets;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Fiscal\FiscalTicketService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class TicketService
{
    public function __construct(
        private readonly TicketSettingsService $settings,
        private readonly FiscalTicketService $fiscalTickets,
    ) {}

    /**
     * Construye el documento único utilizado
     * para visualizar e imprimir un ticket.
     *
     * @return array<string, mixed>
     */
    public function forOrder(
        Tenant $tenant,
        Order $order,
    ): array {
        /*
        |--------------------------------------------------------------------------
        | Settings
        |--------------------------------------------------------------------------
        */

        $order->loadMissing('branch');
        $branch = $order->branch;

        $setting =
            $this->settings
                ->setting(
                    $tenant,
                    $branch,
                );

        $meta =
            $this->settings
                ->meta(
                    $tenant,
                    $branch,
                );

        /*
        |--------------------------------------------------------------------------
        | Order snapshots
        |--------------------------------------------------------------------------
        */

        $order->loadMissing(
            'items',
            'ticket.invoice'
        );

        $invoiceUrl =
            null;

        $invoiceAccessCode =
            null;

        $invoiceEntryUrl =
            null;

        $invoiceQrEnabled =
            (bool) (
                $meta['capabilities']['invoice_qr']
                ?? false
            )
            &&
            (bool) (
                $meta['invoice_qr']['ready']
                ?? false
            )
            &&
            (bool) ($meta['sources']['branch_ready'] ?? false)
            &&
            (bool)
            $setting
                ->show_invoice_qr;

        if (
            $invoiceQrEnabled
            &&
            $order->ticket
            &&
            $order->ticket
                ->fiscal_eligible
            &&
            ! $order->ticket->used_at
            &&
            $order->ticket
            &&
            $order->ticket
                ->expires_at
                ->isFuture()
        ) {
            $invoiceUrl =
                $this->fiscalTickets
                    ->publicUrl(
                        $order->ticket
                    );

            $invoiceAccessCode =
                $this->fiscalTickets
                    ->formattedAccessCode(
                        $order->ticket
                    );

            $invoiceEntryUrl =
                $this->fiscalTickets
                    ->entryUrl();
        }

        /*
        |--------------------------------------------------------------------------
        | Cashier
        |--------------------------------------------------------------------------
        |
        | No dependemos todavía de una relación
        | creator() en Order.
        |
        | created_by ya pertenece a la venta.
        |
        */

        $cashierName =
            null;

        $createdBy =
            $order->getAttribute(
                'created_by'
            );

        if ($createdBy !== null) {
            $cashierName =
                User::query()
                    ->whereKey(
                        $createdBy
                    )
                    ->value(
                        'name'
                    );
        }

        /*
        |--------------------------------------------------------------------------
        | Cash values
        |--------------------------------------------------------------------------
        */

        $amountReceived =
            $order->getAttribute(
                'amount_received'
            );

        $changeAmount =
            $order->getAttribute(
                'change_amount'
            );

        /*
        |--------------------------------------------------------------------------
        | Ticket document
        |--------------------------------------------------------------------------
        */

        return [
            'timezone' => (string) (
                $branch?->timezone ?: $tenant->timezone
                ?: config(
                    'app.timezone',
                    'UTC'
                )
            ),

            /*
            |--------------------------------------------------------------------------
            | Business
            |--------------------------------------------------------------------------
            */

            'business' => [
                'name' => $meta['business']['name'],

                'phone' => $meta['business']['phone'],

                'public_email' => $meta['business']['public_email'],

                'logo_url' => $meta['business']['logo_url'],
            ],

            'branch' => $meta['branch'],

            /*
            |--------------------------------------------------------------------------
            | Settings
            |--------------------------------------------------------------------------
            */

            'settings' => [
                'header_text' => $setting
                    ->header_text,

                'footer_text' => $setting
                    ->footer_text,

                'additional_text' => $setting
                    ->additional_text,

                'visibility' => [
                    'logo' => (bool)
                    $setting
                        ->show_logo,

                    'business_name' => (bool)
                    $setting
                        ->show_business_name,

                    'branch_name' => (bool)
                    $setting
                        ->show_branch_name,

                    'address' => (bool)
                    $setting
                        ->show_address,

                    'phone' => (bool)
                    $setting
                        ->show_phone,

                    'email' => (bool)
                    $setting
                        ->show_email,

                    'rfc' => (bool)
                    $setting
                        ->show_rfc,

                    'cashier' => (bool)
                    $setting
                        ->show_cashier,

                    'folio' => (bool)
                    $setting
                        ->show_folio,

                    'datetime' => (bool)
                    $setting
                        ->show_datetime,

                    'order_type' => (bool)
                    $setting
                        ->show_order_type,

                    'unit_price' => (bool)
                    $setting
                        ->show_unit_price,

                    'tax_breakdown' => (bool)
                    $setting
                        ->show_tax_breakdown,

                    'payment_method' => (bool)
                    $setting
                        ->show_payment_method,

                    'cash_received' => (bool)
                    $setting
                        ->show_cash_received,

                    'change' => (bool)
                    $setting
                        ->show_change,
                ],

                'invoice' => [
                    'show_qr' => $invoiceQrEnabled
                        &&
                        $invoiceUrl !== null,

                    'qr_text' => $setting
                        ->invoice_qr_text,

                    'url' => $invoiceUrl,

                    'access_code' => $invoiceAccessCode,

                    'entry_url' => $invoiceEntryUrl,
                ],

                'printing' => [
                    'paper_width_mm' => (int)
                    $setting
                        ->paper_width_mm,

                    'auto_print' => (bool)
                    $setting
                        ->auto_print,

                    'copies' => (int)
                    $setting
                        ->copies,
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | Sale
            |--------------------------------------------------------------------------
            */

            'sale' => [
                /*
                 * UUID público.
                 */
                'id' => $order
                    ->public_id,

                'folio' => $order
                    ->folio,

                'paid_at' => $order
                    ->paid_at
                    ?->toISOString(),

                'order_type' => $order
                    ->order_type,

                'cashier' => $cashierName,

                'subtotal' => (float)
                $order
                    ->subtotal,

                'tax_total' => (float)
                $order
                    ->tax_total,

                'total' => (float)
                $order
                    ->total,

                'payment_method' => $order
                    ->payment_method,

                'amount_received' => $amountReceived !==
                    null
                    ? (float)
                    $amountReceived
                    : null,

                'change_amount' => $changeAmount !==
                    null
                    ? (float)
                    $changeAmount
                    : null,

                'items' => $order
                    ->items
                    ->map(
                        fn (OrderItem $item): array => $this->ticketItem($item)
                    )
                    ->values()
                    ->all(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function ticketItem(OrderItem $item): array
    {
        $modifierTotal = BigDecimal::of('0.00');
        $modifiers = collect($item->modifiers_snapshot ?? [])->map(function (array $modifier) use (&$modifierTotal): array {
            $price = BigDecimal::of((string) ($modifier['price_adjustment'] ?? '0.00'))->toScale(2, RoundingMode::HalfUp);
            $modifierTotal = $modifierTotal->plus($price);

            return [
                'group_name' => (string) ($modifier['group_name'] ?? ''),
                'option_name' => (string) ($modifier['option_name'] ?? ''),
                'price_adjustment' => (float) (string) $price,
            ];
        })->values()->all();

        $unitPrice = BigDecimal::of((string) $item->unit_price_snapshot)->toScale(2, RoundingMode::HalfUp);
        $baseUnitPrice = $unitPrice->minus($modifierTotal)->toScale(2, RoundingMode::HalfUp);

        return [
            'name' => $item->product_name_snapshot,
            'quantity' => (int) $item->quantity,
            'base_unit_price' => (float) (string) $baseUnitPrice,
            'unit_price' => (float) (string) $unitPrice,
            'line_total' => (float) $item->line_total,
            'modifiers' => $modifiers,
        ];
    }
}
