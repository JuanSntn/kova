<?php

namespace App\Services\Sales;

use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Subscriptions\ConsumptionPeriod;
use App\Services\Subscriptions\EntitlementService;

final class SalesPeriodService
{
    public function __construct(private EntitlementService $access, private ConsumptionPeriod $periods) {}

    public function current(Tenant $tenant, Plan $plan): array
    {
        $s = $this->access->current($tenant);
        if (! $s) {
            throw new \DomainException('No hay contrato vigente.');
        }
        $period = $this->periods->for($s);
        // Existing report builders deliberately mutate copies while iterating days.
        $period['starts_at'] = $period['starts_at']->toMutable();
        $period['ends_at'] = $period['ends_at']->toMutable();

        return $period;
    }
}
