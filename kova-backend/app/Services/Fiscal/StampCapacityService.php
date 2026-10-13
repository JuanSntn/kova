<?php

namespace App\Services\Fiscal;

use App\Models\Feature;
use App\Models\Invoice;
use App\Models\SubscriptionOveragePolicy;
use App\Models\Tenant;
use App\Models\UsagePeriod;
use App\Models\UsageReservation;
use App\Services\Subscriptions\ConsumptionPeriod;
use App\Services\Subscriptions\EntitlementService;
use DomainException;
use Illuminate\Support\Facades\DB;

final class StampCapacityService
{
    public function __construct(private EntitlementService $entitlements, private ConsumptionPeriod $periods) {}
    public function reserve(Tenant $tenant, Invoice $invoice): UsageReservation {
        return DB::transaction(function () use ($tenant, $invoice) {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $existing = UsageReservation::query()->where('invoice_id', $invoice->id)->lockForUpdate()->first();
            if ($existing && in_array($existing->status, ['reserved', 'reconciliation_required', 'confirmed'], true)) {
                return $existing;
            }
            if ($existing && $existing->status !== 'released') {
                throw new DomainException('La reserva fiscal no puede reutilizarse en su estado actual.');
            }
            $subscription = $this->entitlements->current($tenant);
            if (! $subscription || ! ($this->entitlements->resolve($subscription)['allows_invoicing'] ?? false)) {
                throw new DomainException('El contrato vigente no incluye facturación.');
            }
            $resolved = $this->entitlements->resolve($subscription);
            $feature = Feature::query()->where('code', 'included_stamps')->firstOrFail();
            $window = $this->periods->for($subscription);
            UsagePeriod::query()->firstOrCreate(['plan_subscription_id' => $subscription->id, 'feature_id' => $feature->id, 'starts_at' => $window['starts_at']], ['ends_at' => $window['ends_at'], 'included' => $resolved['included_stamps']]);
            $period = UsagePeriod::query()->where('plan_subscription_id', $subscription->id)->where('feature_id', $feature->id)->where('starts_at', $window['starts_at'])->lockForUpdate()->firstOrFail();
            $reserved = UsageReservation::query()->where('usage_period_id', $period->id)->whereIn('status', ['reserved', 'reconciliation_required'])->count();
            $position = (int) $period->consumed + $reserved + 1;
            $included = $period->included;
            $isOverage = $included !== null && $position > (int) $included;
            $policy = null;
            if ($isOverage) {
                $policy = SubscriptionOveragePolicy::query()->where('plan_subscription_id', $subscription->id)->where('feature_id', $feature->id)->where('enabled', true)->whereNull('effective_until')->latest('id')->first();
                if (! $policy) {
                    throw new DomainException('Terminaste los timbres incluidos y los excedentes están desactivados.');
                }
            }
            $sequence = max((int) ($period->events()->max('sequence_number') ?? 0), (int) (UsageReservation::query()->where('usage_period_id', $period->id)->max('sequence_number') ?? 0)) + 1;
            $values = ['usage_period_id' => $period->id, 'subscription_overage_policy_id' => $policy?->id, 'sequence_number' => $sequence, 'usage_type' => $isOverage ? 'overage' : 'included', 'addon_price_id' => $policy?->addon_price_id, 'unit_amount' => $policy?->unit_amount, 'tax_rate_basis_points' => $policy?->tax_rate_basis_points, 'estimated_unit_total' => $policy?->estimated_unit_total, 'currency' => $policy?->currency, 'status' => 'reserved', 'expires_at' => now()->addMinutes((int) config('fiscal.reservation_lifetime_minutes')), 'confirmed_at' => null, 'released_at' => null];
            if ($existing) {
                $existing->update($values);
                return $existing->refresh();
            }
            return UsageReservation::query()->create(['invoice_id' => $invoice->id, ...$values]);
        }, 3);
    }
    public function markUncertain(UsageReservation $reservation): void {
        $reservation->update(['status' => 'reconciliation_required']);
    }
}
