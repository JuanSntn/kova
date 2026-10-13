<?php

namespace App\Services\Plans;

use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Subscriptions\CatalogService;
use App\Services\Subscriptions\EntitlementService;

/** Compatibility projection for POS consumers; never saved back to the catalog. */
final class TenantPlanService
{
    public function __construct(private EntitlementService $access, private CatalogService $catalog) {}

    public function current(Tenant $tenant): Plan
    {
        $s = $this->access->current($tenant);
        if (! $s) {
            throw new \DomainException('El negocio no tiene contrato vigente.');
        }
        $plan = clone $s->version->plan;
        $values = $this->access->resolve($s);
        if (isset($values['monthly_sales_limit'])) {
            $values['monthly_sales_limit'] = $this->catalog->decimal($values['monthly_sales_limit']);
        }
        $plan->forceFill(array_merge($values, ['name' => $s->version->name, 'monthly_price' => $this->catalog->decimal($s->price->amount)]));

        return $plan;
    }
}
