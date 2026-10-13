<?php

namespace App\Domain\Billing\Contracts;

use App\Models\Tenant;
use App\Models\User;

interface BillingGateway
{
    public function createCheckout(Tenant $tenant, User $user, string $plan, string $operationId, string $interval = 'month', ?string $offer = null, array $retainedBranches = [], array $retainedMemberships = []): string;

    public function createPortal(Tenant $tenant): string;

    public function cancelSubscription(Tenant $tenant, array $retainedBranches = [], array $retainedMemberships = []): void;

    public function reactivateSubscription(Tenant $tenant): void;

    public function listInvoices(Tenant $tenant, int $limit = 5, ?string $startingAfter = null): array;
}
