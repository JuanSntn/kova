<?php

namespace App\Services\Tickets;

use App\Enums\PlanCode;
use App\Models\Tenant;
use App\Services\Plans\TenantPlanService;

final class TicketCapabilityService
{
    public function __construct(
        private readonly TenantPlanService $plans,
    ) {}

    /**
     * @return array<string, bool>
     */
    public function for(
        Tenant $tenant,
    ): array {
        $plan =
            $this->plans
            ->current(
                $tenant
            );

        $code =
            $plan->code
            instanceof PlanCode
            ? $plan->code
            : PlanCode::from(
                (string) $plan->code
            );

        $isPaid =
            in_array(
                $code,
                [
                    PlanCode::Business,
                    PlanCode::Restaurant,
                ],
                true,
            );

        $isRestaurant =
            $code ===
            PlanCode::Restaurant;

        return [
            /*
            |--------------------------------------------------------------------------
            | Business
            |--------------------------------------------------------------------------
            */

            'logo' => true,
            'business_name' => true,
            'phone' => true,
            'email' => true,

            /*
             * Plan Free no necesita
             * datos de sucursal impresos.
             */
            'branch_name' =>
            $isPaid,

            'address' =>
            $isPaid,

            /*
            |--------------------------------------------------------------------------
            | Sale
            |--------------------------------------------------------------------------
            */

            'cashier' => true,
            'folio' => true,
            'datetime' => true,

            /*
             * Free y Business solo
             * trabajan Mostrador.
             *
             * Restaurante sí puede
             * tener Mesa + Mostrador.
             */
            'order_type' =>
            $isRestaurant,

            'unit_price' => true,
            'tax_breakdown' => true,
            'payment_method' => true,
            'cash_received' => true,
            'change' => true,

            /*
            |--------------------------------------------------------------------------
            | Fiscal
            |--------------------------------------------------------------------------
            */

            'rfc' =>
            $isPaid,

            'invoice_qr' =>
            $isPaid,

            /*
            |--------------------------------------------------------------------------
            | Other
            |--------------------------------------------------------------------------
            */

            'custom_texts' => true,
            'printing' => true,
        ];
    }
}
