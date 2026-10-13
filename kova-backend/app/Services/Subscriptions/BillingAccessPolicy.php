<?php

namespace App\Services\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use Carbon\CarbonInterface;

final class BillingAccessPolicy
{
    private const VERIFICATION_OUTAGE_HOURS = 24;

    public function isWritable(
        PlanSubscription $subscription,
        ?CarbonInterface $at = null,
    ): bool {
        $at ??= now();

        if ($subscription->status === SubscriptionStatus::Suspended) {
            return false;
        }

        if (
            ! $subscription->grace_ends_at
            || $at->lt($subscription->grace_ends_at)
        ) {
            return true;
        }

        if (! $subscription->billing_verification_failed_since) {
            return false;
        }

        $outageStartsAt = $subscription->billing_verification_failed_since
            ->greaterThan($subscription->grace_ends_at)
                ? $subscription->billing_verification_failed_since
                : $subscription->grace_ends_at;

        return $at->lt(
            $outageStartsAt->copy()->addHours(
                self::VERIFICATION_OUTAGE_HOURS
            )
        );
    }

    public function recordVerification(
        PlanSubscription $subscription,
        bool $successful,
    ): void {
        if ($successful) {
            $subscription->update([
                'last_billing_verified_at' => now(),
                'billing_verification_failed_since' => null,
            ]);

            return;
        }

        if (! $subscription->billing_verification_failed_since) {
            $subscription->update([
                'billing_verification_failed_since' => now(),
            ]);
        }
    }

    public function applyExpiredGrace(Tenant $tenant): void
    {
        $tenant->planSubscriptions()
            ->whereNotNull('current_tenant_id')
            ->whereNotNull('grace_ends_at')
            ->where('grace_ends_at', '<=', now())
            ->get()
            ->each(function (PlanSubscription $subscription): void {
                if (! $this->isWritable($subscription)) {
                    $subscription->update([
                        'status' => SubscriptionStatus::Suspended,
                    ]);
                }
            });
    }
}
