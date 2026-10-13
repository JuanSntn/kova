<?php

namespace App\Services\Subscriptions;

use App\Models\BillingOutbox;
use App\Models\BillingWebhookEvent;
use App\Models\PlanSubscription;
use App\Models\SubscriptionChange;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class StripeWebhookProcessor
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private StripeContractSynchronizer $sync,
    ) {}

    public function process(array $payload): void
    {
        $type = $payload['type'] ?? '';

        if (! isset($payload['id'])) {
            throw new \DomainException('Webhook sin identificador.');
        }

        if ((bool) ($payload['livemode'] ?? false) !== (bool) config('billing.livemode', false)) {
            throw new \DomainException(
                'El modo del webhook no coincide con el entorno.'
            );
        }

        BillingWebhookEvent::firstOrCreate(
            ['stripe_event_id' => $payload['id']],
            [
                'type' => $type,
                'payload' => $payload,
            ]
        );

        $lockToken = (string) Str::uuid();

        $claimed = DB::transaction(function () use ($payload, $lockToken): bool {
            $row = BillingWebhookEvent::query()
                ->where('stripe_event_id', $payload['id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($row->status === 'completed') {
                return false;
            }

            if (
                $row->status === 'processing'
                && $row->locked_at?->gt(now()->subMinutes(10))
            ) {
                return false;
            }

            $row->update([
                'status' => 'processing',
                'locked_at' => now(),
                'lock_token' => $lockToken,
                'last_error' => null,
            ]);

            return true;
        });

        if (! $claimed) {
            return;
        }

        try {
            $data = $payload['data']['object'];

            if (str_starts_with($type, 'checkout.session.')) {
                $this->handleCheckoutEvent($data);
            } else {
                $this->handleSubscriptionOrInvoiceEvent($type, $data);
            }

            $completed = BillingWebhookEvent::query()
                ->where('stripe_event_id', $payload['id'])
                ->where('status', 'processing')
                ->where('lock_token', $lockToken)
                ->update([
                    'status' => 'completed',
                    'locked_at' => null,
                    'lock_token' => null,
                    'processed_at' => now(),
                    'last_error' => null,
                ]);

            if ($completed !== 1) {
                throw new \DomainException(
                    'El webhook perdió su lease y requiere reconciliación.'
                );
            }
        } catch (Throwable $e) {
            BillingWebhookEvent::query()
                ->where('stripe_event_id', $payload['id'])
                ->where('status', 'processing')
                ->where('lock_token', $lockToken)
                ->update([
                    'status' => 'failed',
                    'locked_at' => null,
                    'lock_token' => null,
                    'last_error' => mb_substr($e->getMessage(), 0, 2000),
                ]);

            throw $e;
        }
    }

    private function handleCheckoutEvent(array $data): void
    {
        $tenant = $this->tenantForPayload($data);

        if (! $tenant) {
            return;
        }

        $this->currentTenant->run(
            $tenant,
            function () use ($tenant, $data): void {
                $contract = $tenant->planSubscriptions()
                    ->where(
                        'public_id',
                        data_get(
                            $data,
                            'metadata.contract_public_id'
                        )
                    )
                    ->first();

                if (! $contract) {
                    return;
                }

                $operation = app(BillingReconciler::class)
                    ->checkoutOperation($contract);

                if (
                    ! $operation
                    || ! isset($operation->result['session_id'])
                ) {
                    return;
                }

                app(BillingReconciler::class)
                    ->checkout($operation);

                $this->processReadyOperations($tenant);
            }
        );
    }

    private function handleSubscriptionOrInvoiceEvent(string $type, array $data): void
    {
        $tenant = $this->tenantForPayload($data);

        if (! $tenant) {
            return;
        }

        $id = str_starts_with($type, 'customer.subscription.')
            ? $data['id']
            : ($data['subscription']
                ?? data_get(
                    $data,
                    'parent.subscription_details.subscription'
                ));

        if (! $id) {
            return;
        }

        $this->currentTenant->run(
            $tenant,
            function () use ($tenant, $id, $type): void {
                $remote = app(StripeStateReader::class)
                    ->subscription($tenant, $id);

                $subscription = $this->sync->sync(
                    $tenant,
                    $remote
                );

                if (
                    $type
                    === 'customer.subscription.pending_update_expired'
                ) {
                    $this->expirePendingPlanChange(
                        $subscription,
                        $remote,
                    );
                }

                $this->processReadyOperations($tenant);
            }
        );
    }

    private function expirePendingPlanChange(
        PlanSubscription $subscription,
        object $remote,
    ): void {
        if (data_get($remote, 'metadata.billing_role') !== 'base') {
            return;
        }

        $invoice = data_get($remote, 'latest_invoice');
        $invoiceId = is_string($invoice)
            ? $invoice
            : data_get($invoice, 'id');

        if (! $invoiceId) {
            return;
        }

        $changes = SubscriptionChange::query()
            ->where(
                'plan_subscription_id',
                $subscription->id
            )
            ->where('kind', 'plan')
            ->where('change_mode', 'immediate')
            ->where('status', 'processing')
            ->get();

        $change = $changes->first(function (SubscriptionChange $candidate) use ($invoiceId): bool {
            $operation = BillingOutbox::query()
                ->where('kind', 'upgrade_plan')
                ->where('payload->change_id', $candidate->id)
                ->first();

            return $operation
                && data_get($operation->result, 'invoice_id') === $invoiceId;
        });

        if (! $change) {
            return;
        }

        SubscriptionChange::query()
            ->whereKey($change->id)
            ->where('status', 'processing')
            ->update([
                'status' => 'cancelled',
                'completed_at' => now(),
            ]);
    }

    private function tenantForPayload(array $data): ?Tenant
    {
        $tenantPublicId = data_get($data, 'metadata.tenant_public_id');

        if ($tenantPublicId) {
            $tenant = Tenant::query()
                ->where('public_id', $tenantPublicId)
                ->first();

            if ($tenant) {
                return $tenant;
            }
        }

        return Tenant::query()
            ->where('stripe_id', $data['customer'] ?? null)
            ->first();
    }

    private function processReadyOperations(Tenant $tenant): void
    {
        $this->currentTenant->run(
            $tenant,
            function (): void {
                BillingOutbox::query()
                    ->whereIn('kind', [
                        'provision_extras',
                        'transition_extras',
                        'close_extras',
                    ])
                    ->where('status', 'pending')
                    ->where('available_at', '<=', now())
                    ->orderBy('id')
                    ->get()
                    ->each(
                        fn (BillingOutbox $operation) => app(BillingProcessor::class)
                            ->run($operation)
                    );
            }
        );
    }
}
