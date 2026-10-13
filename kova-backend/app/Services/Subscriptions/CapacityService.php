<?php

namespace App\Services\Subscriptions;

use App\Exceptions\PlanLimitExceededException;
use App\Models\Tenant;
use DomainException;

final class CapacityService
{
    public function __construct(
        private EntitlementService $access,
        private BillingAccessPolicy $billingAccess,
    ) {}

    public function assertAdditional(Tenant $tenant, string $feature, int $current, int $quantity = 1, ?string $message = null): void
    {
        $this->access->assertWritable($tenant);
        $s = $this->access->current($tenant);
        $limits = $this->access->resolve($s);
        if (! array_key_exists($feature, $limits)) {
            throw new DomainException('Capacidad no configurada.');
        }
        $limit = $limits[$feature];
        if ($limit !== null && $current + $quantity > $limit) {
            throw new PlanLimitExceededException($message ?? 'Se alcanzó el límite contratado para '.$feature.'.');
        }
    }

    /**
     * @return array{limit: int|null, used: int, remaining: int|null, unlimited: bool, can_add: bool, reason: string|null}
     */
    public function usage(Tenant $tenant, string $feature, int $current): array
    {
        $subscription = $this->access->current($tenant);
        if (! $subscription) {
            return ['limit' => 0, 'used' => $current, 'remaining' => 0, 'unlimited' => false, 'can_add' => false, 'reason' => 'contract_missing'];
        }

        $values = $this->access->resolve($subscription);
        $limit = array_key_exists($feature, $values) ? $values[$feature] : 0;
        $writable = $this->billingAccess->isWritable($subscription);
        $unlimited = $limit === null;
        $remaining = $unlimited ? null : max(0, (int) $limit - $current);

        return [
            'limit' => $unlimited ? null : (int) $limit,
            'used' => $current,
            'remaining' => $remaining,
            'unlimited' => $unlimited,
            'can_add' => $writable && ($unlimited || $remaining > 0),
            'reason' => ! $writable ? 'subscription_read_only' : (($unlimited || $remaining > 0) ? null : 'limit_reached'),
        ];
    }

    public function assertFeature(Tenant $tenant, string $feature): void
    {
        $this->access->assertWritable($tenant);
        if (! $this->access->allows($tenant, $feature)) {
            throw new PlanLimitExceededException('El contrato no incluye esta función.');
        }
    }
}
