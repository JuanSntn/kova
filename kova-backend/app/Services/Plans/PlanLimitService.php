<?php

namespace App\Services\Plans;

use App\Exceptions\DomainConflictException;
use App\Exceptions\PlanLimitExceededException;
use App\Models\Tenant;
use App\Services\Sales\SalesPeriodService;
use App\Services\Subscriptions\CatalogAccessService;
use Illuminate\Support\Facades\DB;

final class PlanLimitService
{
    public function __construct(
        private readonly TenantPlanService $plans,
        private readonly SalesPeriodService $salesPeriods,
        private readonly CatalogAccessService $catalogAccess,
    ) {}

    public function assertCanCreateProduct(
        Tenant $tenant
    ): void {
        $this->catalogAccess->assertCanCreateProduct($tenant);
    }

    public function assertCanCreateCategory(
        Tenant $tenant
    ): void {
        $this->catalogAccess->assertCanCreateCategory($tenant);
    }

    public function assertCanAddMonthlySales(
        Tenant $tenant,
        string|int|float $newSaleTotal,
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

        $totals = DB::table('orders')
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
            ->pluck('total');

        $projectedTotal = \Brick\Math\BigDecimal::of((string) $newSaleTotal);
        foreach ($totals as $total) {
            $projectedTotal = $projectedTotal->plus((string) $total);
        }

        if ($projectedTotal->isGreaterThan((string) $limit)) {
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
}
