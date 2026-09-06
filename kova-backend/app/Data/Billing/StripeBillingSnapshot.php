<?php

namespace App\Data\Billing;

final readonly class StripeBillingSnapshot
{
    /**
     * @param array<string, mixed>|null $paymentMethod
     * @param array<string, mixed>|null $billingDetails
     */
    public function __construct(
        public bool $customerExists,
        public bool $subscriptionLookupSucceeded,
        public ?StripeSubscriptionData $currentSubscription,
        public ?StripeSubscriptionData $displaySubscription,
        public ?array $paymentMethod,
        public ?array $billingDetails,
    ) {
    }

    public static function withoutCustomer(): self
    {
        return new self(
            customerExists: false,
            subscriptionLookupSucceeded: false,
            currentSubscription: null,
            displaySubscription: null,
            paymentMethod: null,
            billingDetails: null,
        );
    }
}
