<?php

namespace App\Data\Billing;

use Carbon\CarbonImmutable;

final readonly class StripeSubscriptionData
{
    /**
     * @param array<int, string> $priceIds
     */
    public function __construct(
        public string $id,
        public string $status,
        public array $priceIds,
        public bool $cancelAtPeriodEnd,
        public ?CarbonImmutable $currentPeriodEndsAt,
        public ?CarbonImmutable $endedAt,
        public int $createdAt,
    ) {
    }

    public function isCurrent(): bool
    {
        return in_array($this->status, [
            'active',
            'trialing',
            'past_due',
        ], true);
    }

    public function isPastDue(): bool
    {
        return $this->status === 'past_due';
    }

    public function normalizedStatus(): string
    {
        return match ($this->status) {
            'canceled' => 'cancelled',
            'trialing' => 'active',
            default => $this->status,
        };
    }
}
