<?php

namespace App\Domain\Billing\Contracts;

use App\Enums\PlanCode;
use App\Models\Tenant;
use App\Models\User;

interface BillingGateway
{
    public function createCheckout(Tenant $tenant, User $user, PlanCode $plan, string $operationId): string;

    public function createPortal(Tenant $tenant): string;

    public function cancelSubscription(Tenant $tenant): void;

    public function reactivateSubscription(Tenant $tenant): void;

    public function listInvoices(Tenant $tenant, int $limit = 5, ?string $startingAfter = null,): array;
}
