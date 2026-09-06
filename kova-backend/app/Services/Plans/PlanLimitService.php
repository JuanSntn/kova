<?php

namespace App\Services\Plans;

use App\Exceptions\PlanLimitExceededException;
use App\Exceptions\DomainConflictException;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use Illuminate\Support\Carbon;
use App\Enums\PlanCode;
use App\Services\Sales\SalesPeriodService;

final class PlanLimitService
{
    public function __construct(
        private readonly TenantPlanService $plans,
        private readonly SalesPeriodService $salesPeriods,
    ) {}

    public function assertCanCreateProduct(
        Tenant $tenant
    ): void {
        $plan = $this->plans->current($tenant);

        $limit = $plan->max_products;

        // NULL significa ilimitado
        if ($limit === null) {
            return;
        }

        $currentProducts = $tenant
            ->products()
            ->count();

        if ($currentProducts >= $limit) {
            throw new PlanLimitExceededException(
                sprintf(
                    'Tu plan %s permite un máximo de %d productos.',
                    $plan->name,
                    $limit,
                )
            );
        }
    }

    public function assertCanCreateCategory(
        Tenant $tenant
    ): void {
        $plan = $this->plans->current($tenant);

        $limit = $plan->max_categories;

        /*
     * Límite técnico de seguridad.
     *
     * Este sí incluye categorías eliminadas
     * mediante SoftDeletes para evitar que
     * alguien cree y elimine miles de registros.
     */
        $historicalLimit = 1000;

        $historicalCategories = $tenant
            ->categories()
            ->withTrashed()
            ->count();

        if ($historicalCategories >= $historicalLimit) {
            throw new PlanLimitExceededException(
                'Se alcanzó el límite técnico de categorías para este negocio.'
            );
        }

        /*
     * NULL significa ilimitado comercialmente.
     *
     * El límite técnico anterior sigue aplicando
     * aunque el plan sea ilimitado.
     */
        if ($limit === null) {
            return;
        }

        /*
     * Aquí NO usamos withTrashed().
     *
     * Las categorías eliminadas no consumen
     * el límite comercial del plan.
     */
        $currentCategories = $tenant
            ->categories()
            ->count();

        if ($currentCategories >= $limit) {
            throw new PlanLimitExceededException(
                sprintf(
                    'Tu plan %s permite un máximo de %d categorías.',
                    $plan->name,
                    $limit,
                )
            );
        }
    }

    // public function assertCanAddMonthlySales(
    //     Tenant $tenant,
    //     float $newSaleTotal,
    // ): void {
    //     $plan = $this->plans->current($tenant);

    //     $limit = $plan->monthly_sales_limit;

    //     if ($limit === null) {
    //         return;
    //     }

    //     $monthStart = now($tenant->timezone)
    //         ->startOfMonth()
    //         ->utc();

    //     $monthEnd = now($tenant->timezone)
    //         ->endOfMonth()
    //         ->utc();

    //     $currentTotal = (float) DB::table('orders')
    //         ->where('tenant_id', $tenant->id)
    //         ->whereNotNull('paid_at')
    //         ->whereBetween('paid_at', [
    //             $monthStart,
    //             $monthEnd,
    //         ])
    //         ->sum('total');

    //     if (($currentTotal + $newSaleTotal) > (float) $limit) {
    //         throw new DomainConflictException(
    //             sprintf(
    //                 'Tu plan %s permite vender hasta $%s MXN al mes.',
    //                 $plan->name,
    //                 number_format((float) $limit, 2),
    //             ),
    //             'MONTHLY_SALES_LIMIT_EXCEEDED',
    //         );
    //     }
    // }

    public function assertCanAddMonthlySales(
        Tenant $tenant,
        float $newSaleTotal,
    ): void {
        $plan = $this->plans->current($tenant);

        $limit = $plan->monthly_sales_limit;

        if ($limit === null) {
            return;
        }

        $period = $this->salesPeriods->current(
            tenant: $tenant,
            plan: $plan,
        );

        $currentTotal = (float) DB::table('orders')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->where(
                'paid_at',
                '>=',
                $period['starts_at'],
            )
            ->where(
                'paid_at',
                '<',
                $period['ends_at'],
            )
            ->sum('total');

        $projectedTotal = round(
            $currentTotal + $newSaleTotal,
            2,
        );

        if ($projectedTotal > (float) $limit) {
            throw new DomainConflictException(
                sprintf(
                    'Tu plan %s permite vender hasta $%s MXN por período.',
                    $plan->name,
                    number_format(
                        (float) $limit,
                        2,
                    ),
                ),
                'MONTHLY_SALES_LIMIT_EXCEEDED',
            );
        }
    }

    /**
     * Free:
     * usa mes calendario.
     *
     * Business / Restaurant:
     * usan el ciclo real sincronizado
     * desde Stripe.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveSalesPeriod(
        Tenant $tenant,
        Plan $plan,
    ): array {
        /*
     * FREE
     */
        if (
            $plan->code ===
            PlanCode::Free
        ) {
            $timezone =
                $tenant->timezone
                ?: config(
                    'app.timezone',
                    'UTC'
                );

            $now =
                now($timezone);

            $periodStart =
                $now
                ->copy()
                ->startOfMonth()
                ->utc();

            $periodEnd =
                $now
                ->copy()
                ->addMonthNoOverflow()
                ->startOfMonth()
                ->utc();

            return [
                $periodStart,
                $periodEnd,
            ];
        }

        /*
     * PLAN PAGADO
     *
     * Buscamos la suscripción Stripe
     * correspondiente al plan que
     * TenantPlanService resolvió.
     */
        $subscription =
            $tenant
            ->planSubscriptions()
            ->where(
                'provider',
                'stripe'
            )
            ->where(
                'plan_id',
                $plan->id
            )
            ->whereIn(
                'status',
                [
                    SubscriptionStatus::Trial->value,
                    SubscriptionStatus::Active->value,
                    SubscriptionStatus::PastDue->value,
                ]
            )
            ->latest(
                'starts_at'
            )
            ->first();

        if (! $subscription) {
            throw new DomainConflictException(
                'No pudimos determinar la suscripción activa del negocio.',
                'SUBSCRIPTION_PERIOD_NOT_RESOLVED',
            );
        }

        if (
            ! $subscription
                ->current_period_starts_at
            ||
            ! $subscription
                ->current_period_ends_at
        ) {
            throw new DomainConflictException(
                'No pudimos determinar el período actual de tu suscripción.',
                'SUBSCRIPTION_PERIOD_NOT_RESOLVED',
            );
        }

        $periodStart =
            $subscription
            ->current_period_starts_at
            ->copy()
            ->utc();

        $periodEnd =
            $subscription
            ->current_period_ends_at
            ->copy()
            ->utc();

        if (
            $periodStart->greaterThanOrEqualTo(
                $periodEnd
            )
        ) {
            throw new DomainConflictException(
                'El período actual de la suscripción no es válido.',
                'SUBSCRIPTION_PERIOD_INVALID',
            );
        }

        return [
            $periodStart,
            $periodEnd,
        ];
    }
}
