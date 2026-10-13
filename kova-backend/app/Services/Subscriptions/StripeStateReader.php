<?php

namespace App\Services\Subscriptions;

use App\Models\Tenant;
use DomainException;
use Stripe\StripeClient;
use Stripe\Subscription;

final class StripeStateReader
{
    public function __construct(private StripeClient $stripe) {}

    public function subscription(Tenant $tenant, string $id): Subscription
    {
        $remote = $this->stripe->subscriptions->retrieve($id, ['expand' => ['latest_invoice']]);
        if ((is_string($remote->customer) ? $remote->customer : $remote->customer->id) !== $tenant->stripe_id) {
            throw new DomainException('Cliente de cobro incorrecto.');
        }
        $due = [];
        foreach ($this->stripe->invoices->all(['customer' => $tenant->stripe_id, 'subscription' => $id, 'status' => 'open', 'limit' => 100])->autoPagingIterator() as $invoice) {
            if ($invoice->amount_remaining > 0 && ($invoice->attempted || ($invoice->due_date && $invoice->due_date <= time()))) {
                $due[] = $invoice->due_date ?? data_get($invoice, 'status_transitions.finalized_at') ?? $invoice->created;
            }
        }
        $remote->kova_unpaid_since = $due ? min($due) : null;
        $remote->kova_debt_checked = true;

        return $remote;
    }
}
