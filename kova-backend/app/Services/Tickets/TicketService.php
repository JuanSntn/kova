<?php

namespace App\Services\Tickets;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;

final class TicketService
{
    public function __construct(
        private readonly TicketSettingsService $settings,
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

        $setting =
            $this->settings
            ->setting(
                $tenant
            );

        $meta =
            $this->settings
            ->meta(
                $tenant
            );

        /*
        |--------------------------------------------------------------------------
        | Order snapshots
        |--------------------------------------------------------------------------
        */

        $order->loadMissing(
            'items'
        );

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
            'timezone' =>
            (string) (
                $tenant->timezone
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
                'name' =>
                $meta['business']['name'],

                'phone' =>
                $meta['business']['phone'],

                'public_email' =>
                $meta['business']['public_email'],

                'logo_url' =>
                $meta['business']['logo_url'],
            ],

            /*
            |--------------------------------------------------------------------------
            | Settings
            |--------------------------------------------------------------------------
            */

            'settings' => [
                'header_text' =>
                $setting
                    ->header_text,

                'footer_text' =>
                $setting
                    ->footer_text,

                'additional_text' =>
                $setting
                    ->additional_text,

                'visibility' => [
                    'logo' =>
                    (bool)
                    $setting
                        ->show_logo,

                    'business_name' =>
                    (bool)
                    $setting
                        ->show_business_name,

                    'branch_name' =>
                    (bool)
                    $setting
                        ->show_branch_name,

                    'address' =>
                    (bool)
                    $setting
                        ->show_address,

                    'phone' =>
                    (bool)
                    $setting
                        ->show_phone,

                    'email' =>
                    (bool)
                    $setting
                        ->show_email,

                    'rfc' =>
                    (bool)
                    $setting
                        ->show_rfc,

                    'cashier' =>
                    (bool)
                    $setting
                        ->show_cashier,

                    'folio' =>
                    (bool)
                    $setting
                        ->show_folio,

                    'datetime' =>
                    (bool)
                    $setting
                        ->show_datetime,

                    'order_type' =>
                    (bool)
                    $setting
                        ->show_order_type,

                    'unit_price' =>
                    (bool)
                    $setting
                        ->show_unit_price,

                    'tax_breakdown' =>
                    (bool)
                    $setting
                        ->show_tax_breakdown,

                    'payment_method' =>
                    (bool)
                    $setting
                        ->show_payment_method,

                    'cash_received' =>
                    (bool)
                    $setting
                        ->show_cash_received,

                    'change' =>
                    (bool)
                    $setting
                        ->show_change,
                ],

                /*
                 * Facturación todavía no se renderiza
                 * porque fiscal_ready sigue en false.
                 */
                'invoice' => [
                    'show_qr' =>
                    (bool)
                    $setting
                        ->show_invoice_qr
                        &&
                        (bool)
                        $meta['sources']['fiscal_ready'],

                    'qr_text' =>
                    $setting
                        ->invoice_qr_text,
                ],

                'printing' => [
                    'paper_width_mm' =>
                    (int)
                    $setting
                        ->paper_width_mm,

                    'auto_print' =>
                    (bool)
                    $setting
                        ->auto_print,

                    'copies' =>
                    (int)
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
                'id' =>
                $order
                    ->public_id,

                'folio' =>
                $order
                    ->folio,

                'paid_at' =>
                $order
                    ->paid_at
                    ?->toISOString(),

                'order_type' =>
                $order
                    ->order_type,

                'cashier' =>
                $cashierName,

                'subtotal' =>
                (float)
                $order
                    ->subtotal,

                'tax_total' =>
                (float)
                $order
                    ->tax_total,

                'total' =>
                (float)
                $order
                    ->total,

                'payment_method' =>
                $order
                    ->payment_method,

                'amount_received' =>
                $amountReceived !==
                    null
                    ? (float)
                    $amountReceived
                    : null,

                'change_amount' =>
                $changeAmount !==
                    null
                    ? (float)
                    $changeAmount
                    : null,

                'items' =>
                $order
                    ->items
                    ->map(
                        fn(
                            $item
                        ): array => [
                            'name' =>
                            $item
                                ->product_name_snapshot,

                            'quantity' =>
                            (int)
                            $item
                                ->quantity,

                            'unit_price' =>
                            (float)
                            $item
                                ->unit_price_snapshot,

                            'line_total' =>
                            (float)
                            $item
                                ->line_total,
                        ]
                    )
                    ->values()
                    ->all(),
            ],
        ];
    }
}
