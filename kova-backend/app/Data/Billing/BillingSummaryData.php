<?php

namespace App\Data\Billing;

use JsonSerializable;

final readonly class BillingSummaryData implements JsonSerializable
{
    /**
     * @param  array<string, mixed>|null  $currentSubscription
     * @param  array<string, mixed>|null  $paymentMethod
     * @param  array<string, mixed>|null  $billingDetails
     * @param  array<string, mixed>  $stripe
     * @param  array<int, array<string, mixed>>  $plans
     */
    public function __construct(
        public string $currentPlan,
        public string $currentPlanName,
        public ?array $currentSubscription,
        public ?array $paymentMethod,
        public ?array $billingDetails,
        public array $actions,
        public array $stripe,
        public array $plans,
        public array $commercial = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'current_plan' => $this->currentPlan,
            'current_plan_name' => $this->currentPlanName,
            'current_subscription' => $this->currentSubscription,
            'payment_method' => $this->paymentMethod,
            'billing_details' => $this->billingDetails,
            'actions' => $this->actions,
            'stripe' => $this->stripe,
            'plans' => $this->plans,
            ...$this->commercial,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
