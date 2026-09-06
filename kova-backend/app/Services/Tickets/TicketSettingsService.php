<?php

namespace App\Services\Tickets;

use App\Models\Tenant;
use App\Models\TenantTicketSetting;
use App\Services\Business\BusinessProfileService;
use Illuminate\Validation\ValidationException;

final class TicketSettingsService
{
    public function __construct(
        private readonly BusinessProfileService $business,
        private readonly TicketCapabilityService $capabilities,
    ) {}

    public function setting(
        Tenant $tenant,
    ): TenantTicketSetting {
        $setting =
            TenantTicketSetting::query()
            ->firstOrCreate(
                [
                    'tenant_id' =>
                    $tenant->getKey(),
                ],
                $this->defaults(),
            );

        /*
         * Si un tenant bajó de plan,
         * apagamos automáticamente
         * opciones que ya no puede usar.
         */
        $this->restrictUnsupported(
            tenant: $tenant,
            setting: $setting,
        );

        return $setting
            ->refresh();
    }

    public function update(
        Tenant $tenant,
        array $data,
    ): TenantTicketSetting {
        if (
            ! $this->business
                ->isComplete(
                    $tenant
                )
        ) {
            throw ValidationException::withMessages([
                'business_profile' =>
                'Configura primero la información de tu negocio.',
            ]);
        }

        $setting =
            $this->setting(
                $tenant
            );

        $data['folio_prefix'] =
            strtoupper(
                trim(
                    $data['folio_prefix']
                )
            );

        /*
         * No confiamos en React para
         * aplicar las capacidades del plan.
         */
        $data =
            $this->sanitizeForPlan(
                tenant: $tenant,
                data: $data,
            );

        /*
         * Si no existe logo,
         * no permitimos marcarlo
         * como visible.
         */
        $business =
            $this->business
            ->data(
                $tenant
            );

        if (
            $business['logo_url'] === null
        ) {
            $data['show_logo'] =
                false;
        }

        $setting->update(
            $data
        );

        return $setting
            ->refresh();
    }

    public function prefix(
        Tenant $tenant,
    ): string {
        return $this
            ->setting(
                $tenant
            )
            ->folio_prefix;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(
        Tenant $tenant,
    ): array {
        $business =
            $this->business
            ->data(
                $tenant
            );

        return [
            'configuration_ready' =>
            $business['is_complete'],

            'missing_business_fields' =>
            $business['missing_fields'],

            'capabilities' =>
            $this
                ->capabilities
                ->for(
                    $tenant
                ),

            /*
             * Estos dos módulos serán
             * nuestras siguientes fases.
             *
             * Todavía no inventamos
             * datos de Branch/Fiscal.
             */
            'sources' => [
                'business_ready' =>
                $business['is_complete'],

                'branch_ready' =>
                false,

                'fiscal_ready' =>
                false,
            ],

            'business' => [
                'name' =>
                $business['name'],

                'phone' =>
                $business['phone'],

                'public_email' =>
                $business['public_email'],

                'logo_url' =>
                $business['logo_url'],
            ],
        ];
    }

    private function restrictUnsupported(
        Tenant $tenant,
        TenantTicketSetting $setting,
    ): void {
        $capabilities =
            $this
            ->capabilities
            ->for(
                $tenant
            );

        $changes = [];

        $map = [
            'show_branch_name' =>
            'branch_name',

            'show_address' =>
            'address',

            'show_rfc' =>
            'rfc',

            'show_order_type' =>
            'order_type',

            'show_invoice_qr' =>
            'invoice_qr',
        ];

        foreach (
            $map as
            $field =>
            $capability
        ) {
            if (
                ! $capabilities[$capability]
                && $setting->$field
            ) {
                $changes[$field] =
                    false;
            }
        }

        if (
            ! $capabilities['invoice_qr']
        ) {
            $changes['invoice_qr_text'] =
                null;
        }

        if (
            $changes !== []
        ) {
            $setting->update(
                $changes
            );
        }
    }

    private function sanitizeForPlan(
        Tenant $tenant,
        array $data,
    ): array {
        $capabilities =
            $this
            ->capabilities
            ->for(
                $tenant
            );

        $map = [
            'show_logo' =>
            'logo',

            'show_business_name' =>
            'business_name',

            'show_branch_name' =>
            'branch_name',

            'show_address' =>
            'address',

            'show_phone' =>
            'phone',

            'show_email' =>
            'email',

            'show_rfc' =>
            'rfc',

            'show_cashier' =>
            'cashier',

            'show_folio' =>
            'folio',

            'show_datetime' =>
            'datetime',

            'show_order_type' =>
            'order_type',

            'show_unit_price' =>
            'unit_price',

            'show_tax_breakdown' =>
            'tax_breakdown',

            'show_payment_method' =>
            'payment_method',

            'show_cash_received' =>
            'cash_received',

            'show_change' =>
            'change',

            'show_invoice_qr' =>
            'invoice_qr',
        ];

        foreach (
            $map as
            $field =>
            $capability
        ) {
            if (
                ! $capabilities[$capability]
            ) {
                $data[$field] =
                    false;
            }
        }

        if (
            ! $capabilities['invoice_qr']
        ) {
            $data['invoice_qr_text'] =
                null;
        }

        return $data;
    }

    private function defaults(): array
    {
        return [
            'folio_prefix' =>
            'POS',

            /*
             * display_name queda sin uso.
             *
             * Conservamos la columna por ahora
             * para no hacer una migración
             * destructiva.
             */
            'display_name' =>
            null,

            'header_text' =>
            null,

            'footer_text' =>
            '¡Gracias por tu compra!',

            'additional_text' =>
            null,

            'show_logo' =>
            true,

            'show_business_name' =>
            true,

            'show_branch_name' =>
            true,

            'show_address' =>
            true,

            'show_phone' =>
            true,

            'show_email' =>
            true,

            'show_rfc' =>
            false,

            'show_cashier' =>
            true,

            'show_folio' =>
            true,

            'show_datetime' =>
            true,

            'show_order_type' =>
            false,

            'show_unit_price' =>
            true,

            'show_tax_breakdown' =>
            true,

            'show_payment_method' =>
            true,

            'show_cash_received' =>
            true,

            'show_change' =>
            true,

            'show_invoice_qr' =>
            false,

            'invoice_qr_text' =>
            'Escanea para solicitar tu factura.',

            'paper_width_mm' =>
            80,

            'auto_print' =>
            false,

            'copies' =>
            1,
        ];
    }
}
