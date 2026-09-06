<?php

namespace App\Services\Billing;

use App\Models\Plan;
use Illuminate\Support\Collection;

final class BillingPlanResolver
{
    /**
     * @var Collection<string, Plan>|null
     */
    private ?Collection $plansByCode = null;

    /**
     * @param array<int, string> $priceIds
     */
    public function resolveByPriceIds(array $priceIds): ?Plan
    {
        if ($priceIds === []) {
            return null;
        }

        foreach (config('billing.plans', []) as $planCode => $configuration) {
            $configuredPriceId = $configuration['price'] ?? null;

            if (! $configuredPriceId || ! in_array($configuredPriceId, $priceIds, true)) {
                continue;
            }

            return $this->findByCode((string) $planCode);
        }

        return null;
    }

    public function findByCode(string $code): ?Plan
    {
        return $this->plansByCode()->get($code);
    }

    public function freePlan(): ?Plan
    {
        return $this->findByCode('free');
    }

    /**
     * @return Collection<int, Plan>
     */
    public function activePlans(): Collection
    {
        return $this->plansByCode()
            ->values()
            ->filter(fn (Plan $plan): bool => (bool) $plan->is_active)
            ->sortBy('monthly_price')
            ->values();
    }

    /**
     * @return Collection<string, Plan>
     */
    private function plansByCode(): Collection
    {
        if ($this->plansByCode !== null) {
            return $this->plansByCode;
        }

        $this->plansByCode = Plan::query()
            ->get()
            ->keyBy(fn (Plan $plan): string => $plan->code->value);

        return $this->plansByCode;
    }
}
