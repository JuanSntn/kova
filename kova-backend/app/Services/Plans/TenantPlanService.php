<?php

namespace App\Services\Plans;

use App\Enums\PlanCode;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Tenant;

final class TenantPlanService
{
    public function current(
        Tenant $tenant
    ): Plan {
        /*
         * Primero buscamos una suscripción Stripe activa.
         *
         * Si existe, esa es la suscripción comercial
         * vigente del tenant.
         */
        $stripeSubscription = $tenant
            ->planSubscriptions()
            ->with('plan')
            ->where(
                'status',
                SubscriptionStatus::Active->value
            )
            ->where('provider', 'stripe')
            ->latest('starts_at')
            ->first();

        if ($stripeSubscription?->plan) {
            return $stripeSubscription->plan;
        }

        /*
         * Si todavía no paga Stripe,
         * buscamos su plan local.
         *
         * Normalmente será Free.
         */
        $localSubscription = $tenant
            ->planSubscriptions()
            ->with('plan')
            ->where(
                'status',
                SubscriptionStatus::Active->value
            )
            ->where('provider', 'local')
            ->latest('starts_at')
            ->first();

        if ($localSubscription?->plan) {
            return $localSubscription->plan;
        }

        /*
         * Último fallback:
         * Free.
         */
        return Plan::query()
            ->where(
                'code',
                PlanCode::Free->value
            )
            ->where('is_active', true)
            ->firstOrFail();
    }
}
