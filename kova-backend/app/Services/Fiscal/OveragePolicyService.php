<?php

namespace App\Services\Fiscal;

use App\Contracts\Billing\StripeBillingGateway;
use App\Domain\Audit\AuditRecorder;
use App\Models\Feature;
use App\Models\SubscriptionOveragePolicy;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Subscriptions\CatalogService;
use App\Services\Subscriptions\EntitlementService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class OveragePolicyService
{
    public function __construct(
        private EntitlementService $entitlements,
        private CatalogService $catalog,
        private StripeBillingGateway $stripe,
        private AuditRecorder $audit,
    ) {}

    public function current(Tenant $tenant): ?SubscriptionOveragePolicy
    {
        $subscription = $this->entitlements->current($tenant);
        if (! $subscription) {
            return null;
        }
        $feature = Feature::query()->where('code', 'included_stamps')->first();
        if (! $feature) {
            return null;
        }

        return SubscriptionOveragePolicy::query()
            ->where('plan_subscription_id', $subscription->id)
            ->where('feature_id', $feature->id)
            ->whereNull('effective_until')
            ->latest('id')
            ->first();
    }

    public function set(Tenant $tenant, User $user, bool $enabled, Request $request): ?SubscriptionOveragePolicy
    {
        $snapshot = $enabled ? $this->stripe->getSnapshot($tenant) : null;

        return DB::transaction(function () use ($tenant, $user, $enabled, $request, $snapshot) {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $subscription = $this->entitlements->current($tenant);
            $feature = Feature::query()->where('code', 'included_stamps')->firstOrFail();
            $current = $subscription ? SubscriptionOveragePolicy::query()
                ->where('plan_subscription_id', $subscription->id)
                ->where('feature_id', $feature->id)
                ->whereNull('effective_until')
                ->latest('id')
                ->first() : null;

            // A tenant must always be able to stop future overage usage, even if
            // its payment method or one of the Stripe components is unhealthy.
            if (! $enabled) {
                if ($current && $current->enabled) {
                    $current->update(['enabled' => false]);
                    $this->audit->record($request, 'billing.stamp_overage_changed', $current, ['enabled' => true], ['enabled' => false]);
                }

                return $current?->refresh();
            }

            if (! $subscription || $subscription->price->amount === 0 || $subscription->status->value !== 'active') {
                throw new DomainException('Necesitas un contrato pagado y activo para permitir timbres excedentes.');
            }
            if (! ($this->entitlements->resolve($subscription)['allows_invoicing'] ?? false)) {
                throw new DomainException('Tu contrato no incluye facturación.');
            }
            $addon = $subscription->addons()
                ->where('status', 'active')
                ->whereHas('version.addon', fn ($query) => $query->where('code', 'extra_stamp'))
                ->with('price')
                ->first();
            if (! $addon) {
                throw new DomainException('El componente mensual de timbres no está activo.');
            }
            if (! $subscription->billingLinks()->where('role', 'extras')->where('status', 'active')->exists()) {
                throw new DomainException('La suscripción de extras todavía no está activa.');
            }
            $this->catalog->mapping($addon->price);
            if ($enabled && (! $snapshot?->customerExists || $snapshot->paymentMethod === null)) {
                throw new DomainException('Agrega un método de pago antes de permitir timbres excedentes.');
            }

            $policy = SubscriptionOveragePolicy::query()->firstOrNew([
                'plan_subscription_id' => $subscription->id,
                'feature_id' => $feature->id,
                'addon_price_id' => $addon->addon_price_id,
            ]);
            $before = $policy->exists ? ['enabled' => (bool) $policy->enabled] : [];
            $policy->fill([
                'subscription_addon_id' => $addon->id,
                'enabled' => $enabled,
                'unit_amount' => $addon->price->amount,
                'tax_rate_basis_points' => 1600,
                'estimated_unit_total' => (int) round($addon->price->amount * 1.16),
                'currency' => $addon->price->currency,
                'effective_from' => $policy->effective_from ?? now(),
                'effective_until' => null,
                'consented_by' => $enabled ? $user->id : $policy->consented_by,
                'consented_at' => $enabled ? now() : $policy->consented_at,
            ])->save();
            $this->audit->record($request, 'billing.stamp_overage_changed', $policy, $before, ['enabled' => $enabled]);

            return $policy->refresh();
        });
    }

    public function closeForContract(int $subscriptionId): void
    {
        SubscriptionOveragePolicy::query()->where('plan_subscription_id', $subscriptionId)->whereNull('effective_until')->update(['enabled' => false, 'effective_until' => now()]);
    }
}
