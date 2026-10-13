<?php

namespace App\Listeners;

use App\Services\Subscriptions\StripeWebhookProcessor;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;

final class SyncStripePlanSubscription
{
    public function __construct(
        private StripeWebhookProcessor $processor,
    ) {}

    public function handle(WebhookReceived|WebhookHandled $event): void
    {
        $type = $event->payload['type'] ?? '';

        if (! $this->handles($event, $type)) {
            return;
        }

        $this->processor->process($event->payload);
    }

    private function handles(WebhookReceived|WebhookHandled $event, string $type): bool
    {
        if ($event instanceof WebhookReceived) {
            return in_array($type, [
                'checkout.session.completed',
                'checkout.session.async_payment_succeeded',
                'checkout.session.async_payment_failed',
                'checkout.session.expired',
                'customer.subscription.pending_update_applied',
                'customer.subscription.pending_update_expired',
                'invoice.payment_failed',
                'invoice.paid',
                'invoice.voided',
            ], true);
        }

        return in_array($type, [
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
            'invoice.payment_action_required',
            'invoice.payment_succeeded',
        ], true);
    }
}
