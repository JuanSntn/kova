<?php

namespace App\Services\Tickets;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\TenantTicketSetting;
use App\Services\Business\BusinessProfileService;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Exceptions\HttpResponseException;

final class TicketSettingsService
{
    public function __construct(
        private readonly BusinessProfileService $business,
        private readonly TicketCapabilityService $capabilities,
        private readonly InvoiceQrReadinessService $qrReadiness,
    ) {}

    public function setting(
        Tenant $tenant,
        ?Branch $branch = null,
    ): TenantTicketSetting {
        $setting =
            clone $this->storedSetting(
                $tenant
            );

        return $this->applyEffectiveRestrictions(
            tenant: $tenant,
            setting: $setting,
            branch: $branch,
        );
    }

    public function update(
        Tenant $tenant,
        array $data,
    ): TenantTicketSetting {
        if ($data['show_invoice_qr'] ?? false) {
            $readiness = $this->qrReadiness->for($tenant);
            if (! $readiness['ready']) {
                throw new HttpResponseException(response()->json([
                    'message' => $readiness['message'],
                    'code' => 'invoice_qr_not_ready',
                    'errors' => ['show_invoice_qr' => [$readiness['message']]],
                    'invoice_qr' => $readiness,
                ], 422));
            }
        }
        if (
            ! $this->business
                ->isComplete(
                    $tenant
                )
        ) {
            throw ValidationException::withMessages([
                'business_profile' => 'Configura primero la información de tu negocio.',
            ]);
        }

        /*
         * Trabajamos con la configuración REAL guardada.
         *
         * No utilizamos setting() aquí porque setting()
         * devuelve una versión efectiva según el plan.
         */
        $setting =
            $this->storedSetting(
                $tenant
            );

        $data['folio_prefix'] =
            strtoupper(
                trim(
                    $data['folio_prefix']
                )
            );

        /*
         * Cuando una capacidad no está disponible,
         * conservamos el valor previamente guardado.
         *
         * Esto evita destruir configuraciones durante
         * un downgrade.
         */
        $data =
            $this->sanitizeForPlan(
                tenant: $tenant,
                setting: $setting,
                data: $data,
            );

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

        return $this->applyEffectiveRestrictions(
            tenant: $tenant,
            setting: $setting->refresh(),
        );
    }

    public function prefix(
        Tenant $tenant,
        ?Branch $branch = null,
    ): string {
        return $this->setting($tenant, $branch)->folio_prefix;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(
        Tenant $tenant,
        ?Branch $branch = null,
    ): array {
        $business =
            $this->business
                ->data(
                    $tenant
                );

        $qr = $this->qrReadiness->for($tenant, (bool) $this->storedSetting($tenant)->show_invoice_qr);

        return [
            'invoice_qr' => $qr,
            'configuration_ready' => $business['is_complete'],

            'missing_business_fields' => $business['missing_fields'],

            'capabilities' => $this->capabilities
                ->for(
                    $tenant
                ),

            'sources' => [
                'business_ready' => $business['is_complete'],

                'branch_ready' => $branch ? $this->qrReadiness->branchReady($tenant, $branch) : $qr['branches_ready'],

                'fiscal_ready' => $qr['fiscal_ready'],
            ],

            'business' => [
                'name' => $business['name'],

                'phone' => $business['phone'],

                'public_email' => $business['public_email'],

                'logo_url' => $business['logo_url'],
            ],

            'branch' => $branch ? [
                'id' => $branch->public_id,
                'code' => $branch->code,
                'name' => $branch->name,
                'phone' => $branch->phone,
                'postal_code' => $branch->postal_code,
                'state' => $branch->state,
                'municipality' => $branch->municipality,
                'locality' => $branch->locality,
                'city' => $branch->city,
                'neighborhood' => $branch->neighborhood,
                'street' => $branch->street,
                'exterior_number' => $branch->exterior_number,
                'interior_number' => $branch->interior_number,
            ] : null,
        ];
    }

    private function storedSetting(
        Tenant $tenant,
    ): TenantTicketSetting {
        return TenantTicketSetting::query()
            ->firstOrCreate(
                [
                    'tenant_id' => $tenant->getKey(),
                ],
                $this->defaults(),
            );
    }

    /**
     * Aplica restricciones efectivas en memoria.
     *
     * NO guarda estos cambios en base de datos.
     *
     * De esta manera un downgrade desactiva
     * temporalmente capacidades sin eliminar
     * la preferencia original del cliente.
     */
    private function applyEffectiveRestrictions(
        Tenant $tenant,
        TenantTicketSetting $setting,
        ?Branch $branch = null,
    ): TenantTicketSetting {
        $capabilities =
            $this->capabilities
                ->for(
                    $tenant
                );

        $map = [
            'show_branch_name' => 'branch_name',

            'show_address' => 'address',

            'show_rfc' => 'rfc',

            'show_order_type' => 'order_type',

            'show_invoice_qr' => 'invoice_qr',
        ];

        foreach (
            $map as $field => $capability
        ) {
            if (
                ! $capabilities[$capability]
            ) {
                $setting->setAttribute(
                    $field,
                    false
                );
            }
        }

        /*
         * Aunque el plan permita facturación,
         * el QR tampoco es efectivo mientras
         * la configuración fiscal no esté lista.
         */
        if (
            ! $this->qrReadiness->for($tenant)['ready']
            || ($branch && ! $this->qrReadiness->branchReady($tenant, $branch))
        ) {
            $setting->setAttribute(
                'show_invoice_qr',
                false
            );
        }

        return $setting;
    }

    /**
     * Evita modificar capacidades que el tenant
     * no puede configurar actualmente.
     *
     * En vez de forzarlas a false, preservamos
     * el valor almacenado previamente.
     */
    private function sanitizeForPlan(
        Tenant $tenant,
        TenantTicketSetting $setting,
        array $data,
    ): array {
        $capabilities =
            $this->capabilities
                ->for(
                    $tenant
                );

        $map = [
            'show_logo' => 'logo',

            'show_business_name' => 'business_name',

            'show_branch_name' => 'branch_name',

            'show_address' => 'address',

            'show_phone' => 'phone',

            'show_email' => 'email',

            'show_rfc' => 'rfc',

            'show_cashier' => 'cashier',

            'show_folio' => 'folio',

            'show_datetime' => 'datetime',

            'show_order_type' => 'order_type',

            'show_unit_price' => 'unit_price',

            'show_tax_breakdown' => 'tax_breakdown',

            'show_payment_method' => 'payment_method',

            'show_cash_received' => 'cash_received',

            'show_change' => 'change',

            'show_invoice_qr' => 'invoice_qr',
        ];

        foreach (
            $map as $field => $capability
        ) {
            if (
                ! $capabilities[$capability]
            ) {
                $data[$field] =
                    (bool) $setting
                        ->getAttribute(
                            $field
                        );
            }
        }

        /*
         * El texto del QR también se conserva
         * mientras facturación no esté disponible.
         */
        if (
            ! $capabilities['invoice_qr']
        ) {
            $data['invoice_qr_text'] =
                $setting
                    ->invoice_qr_text;
        }

        /*
         * Si el plan sí permite facturación pero
         * fiscal todavía no está listo, tampoco
         * permitimos cambiar show_invoice_qr.
         *
         * Conservamos la preferencia previa.
         */
        if (
            $capabilities['invoice_qr']
            &&
            ! $this->qrReadiness->for($tenant)['ready']
        ) {
            $data['show_invoice_qr'] =
                (bool) $setting
                    ->show_invoice_qr;
        }

        return $data;
    }

    private function defaults(): array
    {
        return [
            'folio_prefix' => 'POS',

            'display_name' => null,

            'header_text' => null,

            'footer_text' => '¡Gracias por tu compra!',

            'additional_text' => null,

            'show_logo' => true,

            'show_business_name' => true,

            'show_branch_name' => true,

            'show_address' => true,

            'show_phone' => true,

            'show_email' => true,

            'show_rfc' => false,

            'show_cashier' => true,

            'show_folio' => true,

            'show_datetime' => true,

            'show_order_type' => false,

            'show_unit_price' => true,

            'show_tax_breakdown' => true,

            'show_payment_method' => true,

            'show_cash_received' => true,

            'show_change' => true,

            'show_invoice_qr' => false,

            'invoice_qr_text' => 'Escanea para solicitar tu factura.',

            'paper_width_mm' => 80,

            'auto_print' => false,

            'copies' => 1,
        ];
    }
}
