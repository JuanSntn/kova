<?php

namespace App\Services\Sales;

use App\Enums\PlanCode;
use App\Enums\SubscriptionStatus;
use App\Exceptions\DomainConflictException;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

final class SalesPeriodService
{
    /**
     * @return array{
     *     starts_at: Carbon,
     *     ends_at: Carbon,
     *     type: string
     * }
     */
    public function current(
        Tenant $tenant,
        Plan $plan,
    ): array {
        $planCode = $plan->code instanceof PlanCode
            ? $plan->code
            : PlanCode::from((string) $plan->code);

        /*
         * FREE
         *
         * Siempre usa mes calendario según
         * el timezone del negocio.
         */
        if ($planCode === PlanCode::Free) {
            $timezone = $tenant->timezone
                ?: config('app.timezone', 'UTC');

            $now = now($timezone);

            $start = $now
                ->copy()
                ->startOfMonth()
                ->utc();

            $end = $now
                ->copy()
                ->startOfMonth()
                ->addMonthNoOverflow()
                ->utc();

            return [
                'starts_at' => $start,
                'ends_at' => $end,
                'type' => 'calendar_month',
            ];
        }

        /*
         * PLANES PAGADOS
         *
         * Business / Restaurant usan
         * el ciclo real sincronizado
         * desde Stripe.
         */
        $subscription = $tenant
            ->planSubscriptions()
            ->where('provider', 'stripe')
            ->where('plan_id', $plan->getKey())
            ->whereIn('status', [
                SubscriptionStatus::Trial->value,
                SubscriptionStatus::Active->value,
                SubscriptionStatus::PastDue->value,
            ])
            ->latest('starts_at')
            ->first();

        if (! $subscription) {
            throw new DomainConflictException(
                'No pudimos determinar la suscripción activa del negocio.',
                'SUBSCRIPTION_PERIOD_NOT_RESOLVED',
            );
        }

        if (
            ! $subscription->current_period_starts_at
            || ! $subscription->current_period_ends_at
        ) {
            throw new DomainConflictException(
                'No pudimos determinar el período actual de la suscripción.',
                'SUBSCRIPTION_PERIOD_NOT_RESOLVED',
            );
        }

        $start = $subscription
            ->current_period_starts_at
            ->copy()
            ->utc();

        $end = $subscription
            ->current_period_ends_at
            ->copy()
            ->utc();

        if ($start->greaterThanOrEqualTo($end)) {
            throw new DomainConflictException(
                'El período actual de la suscripción no es válido.',
                'SUBSCRIPTION_PERIOD_INVALID',
            );
        }

        return [
            'starts_at' => $start,
            'ends_at' => $end,
            'type' => 'billing_cycle',
        ];
    }
}
