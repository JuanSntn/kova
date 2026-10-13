<?php

namespace App\Domain\Billing;

use App\Models\Tenant;
use App\Services\Subscriptions\StripeContractSynchronizer;
use Stripe\Subscription;

final class SyncStripeSubscriptionService
{
    public function __construct(private StripeContractSynchronizer $sync) {}

    public function sync(Tenant $tenant, Subscription $subscription, ?int $eventCreatedAt = null): void
    {
        $this->sync->sync($tenant, $subscription);
    }

    public function syncDeleted(Tenant $tenant, Subscription $subscription): void
    {
        $this->sync->sync($tenant, $subscription);
    }
}
