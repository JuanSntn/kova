<?php

namespace App\Services\Fiscal;

use App\Models\Feature;
use App\Models\Tenant;
use App\Models\UsageEvent;
use App\Models\UsagePeriod;
use App\Services\Subscriptions\ConsumptionPeriod;
use App\Services\Subscriptions\EntitlementService;

final class StampUsageSummaryService
{
    public function __construct(private EntitlementService $entitlements, private ConsumptionPeriod $periods, private OveragePolicyService $policies) {}

    public function for(Tenant $tenant): array
    {
        $subscription = $this->entitlements->current($tenant);
        if (! $subscription) {
            return $this->empty();
        }
        $benefits = $this->entitlements->resolve($subscription);
        $included = $benefits['included_stamps'] ?? 0;
        $feature = Feature::query()->where('code', 'included_stamps')->first();
        $window = $this->periods->for($subscription);
        $period = $feature ? UsagePeriod::query()->where('plan_subscription_id', $subscription->id)->where('feature_id', $feature->id)->where('starts_at', $window['starts_at'])->first() : null;
        $events = $period ? UsageEvent::query()->where('usage_period_id', $period->id) : null;
        $used = (int) ($period?->consumed ?? 0);
        $overages = $events ? (clone $events)->where('is_overage', true)->count() : 0;
        $estimated = $events ? (int) (clone $events)->where('is_overage', true)->sum('estimated_unit_total') : 0;
        $policy = $this->policies->current($tenant);
        $addon = $subscription->addons()->whereHas('version.addon', fn ($query) => $query->where('code', 'extra_stamp'))->whereIn('status', ['active', 'pending'])->with('price')->first();

        return [
            'used' => $used,
            'included' => $included,
            'available' => $included === null ? null : max(0, (int) $included - min($used, (int) $included)),
            'overages' => $overages,
            'estimated_total' => $estimated,
            'currency' => $policy?->currency ?? $addon?->price?->currency ?? 'mxn',
            'unit_amount' => $policy?->unit_amount ?? $addon?->price?->amount,
            'estimated_unit_total' => $policy?->estimated_unit_total ?? ($addon ? (int) round($addon->price->amount * 1.16) : null),
            'overage_enabled' => (bool) $policy?->enabled,
            'can_enable_overage' => $subscription->price->amount > 0 && ($benefits['allows_invoicing'] ?? false) && $addon?->status === 'active',
            'period_starts_at' => $window['starts_at'],
            'period_ends_at' => $window['ends_at'],
            'metering' => [
                'pending' => $events ? (clone $events)->where('metering_status', 'pending')->count() : 0,
                'reported' => $events ? (clone $events)->where('metering_status', 'reported')->count() : 0,
                'failed' => $events ? (clone $events)->where('metering_status', 'failed')->count() : 0,
            ],
        ];
    }

    private function empty(): array
    {
        return ['used' => 0, 'included' => 0, 'available' => 0, 'overages' => 0, 'estimated_total' => 0, 'currency' => 'mxn', 'unit_amount' => null, 'estimated_unit_total' => null, 'overage_enabled' => false, 'can_enable_overage' => false, 'period_starts_at' => null, 'period_ends_at' => null, 'metering' => ['pending' => 0, 'reported' => 0, 'failed' => 0]];
    }
}
