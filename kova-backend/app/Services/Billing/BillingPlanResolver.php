<?php

namespace App\Services\Billing;

use App\Models\BillingPriceMapping;
use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Support\Collection;

final class BillingPlanResolver
{
    public function resolveByPriceIds(array $ids): ?Plan
    {
        $mapping = BillingPriceMapping::whereIn('stripe_price_id', $ids)->whereNotNull('plan_price_id')->first();

        return $mapping ? PlanPrice::find($mapping->plan_price_id)?->version?->plan : null;
    }

    public function findByCode(string $code): ?Plan
    {
        return Plan::where('code', $code)->first();
    }

    public function freePlan(): ?Plan
    {
        return $this->findByCode('free');
    }

    public function activePlans(): Collection
    {
        return Plan::where('is_active', true)->get();
    }
}
