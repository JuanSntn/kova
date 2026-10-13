<?php

namespace App\Services\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Models\BillingOutbox;
use App\Models\BillingSubscriptionLink;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\StripeClient;
use Throwable;

final class BillingReconciler
{
    public function __construct(
        private StripeClient $stripe,
        private StripeStateReader $reader,
        private StripeContractSynchronizer $sync,
        private BillingAccessPolicy $billingAccess,
    ) {}

    public function checkout(BillingOutbox $operation, bool $cancelOpen = false): Session
    {
        $contract = $this->contract($operation);

        $session = $this->stripe->checkout->sessions->retrieve(
            $operation->result['session_id'],
            ['expand' => ['subscription.latest_invoice']]
        );

        $this->assertCheckoutBelongsToContract($session, $contract);

        if (
            $cancelOpen
            && $session->status === 'open'
            && $session->payment_status === 'unpaid'
        ) {
            $session = $this->stripe->checkout->sessions->expire(
                $session->id,
                [],
                [
                    'idempotency_key' => hash(
                        'sha256',
                        $contract->tenant->public_id.':'.$operation->operation_key.':expire-checkout'
                    ),
                ]
            );
        }

        $this->storeCheckoutState($operation, $contract, $session);

        if ($session->status === 'expired') {
            $this->closePendingContract($contract);
        }

        if ($session->status === 'complete' && $session->subscription) {
            $id = is_string($session->subscription)
                ? $session->subscription
                : $session->subscription->id;

            $this->sync->sync(
                $contract->tenant,
                $this->reader->subscription($contract->tenant, $id)
            );
        }

        return $session;
    }

    public function cancelPendingCheckout(Tenant $tenant): array
    {
        $contract = $this->pendingContract($tenant);

        if (! $contract) {
            return ['state' => 'none'];
        }

        $operation = $this->checkoutOperation($contract);

        if (! $operation) {
            $this->closePendingContract($contract);

            return ['state' => 'cancelled'];
        }

        if (! isset($operation->result['session_id'])) {
            return $this->cancelCheckoutWithoutSession(
                $contract,
                $operation,
            );
        }

        $session = $this->checkout($operation, true);

        if ($session->status === 'expired') {
            $this->closePendingContract($contract);

            return ['state' => 'cancelled'];
        }

        if ($session->status === 'complete') {
            return $this->pendingSummary($contract->refresh());
        }

        return ['state' => 'cancelled'];
    }

    private function cancelCheckoutWithoutSession(
        PlanSubscription $contract,
        BillingOutbox $operation,
    ): array {
        return DB::transaction(function () use (
            $contract,
            $operation,
        ): array {
            Tenant::query()
                ->whereKey($contract->tenant_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedContract = PlanSubscription::query()
                ->whereKey($contract->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedOperation = BillingOutbox::query()
                ->whereKey($operation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (isset($lockedOperation->result['session_id'])) {
                throw new DomainException(
                    'El checkout ya fue creado. Actualiza su estado antes de cancelarlo.'
                );
            }

            if ($lockedOperation->status === 'processing') {
                throw new DomainException(
                    'El checkout todavía se está preparando. Intenta nuevamente en unos segundos.'
                );
            }

            if ($lockedOperation->status === 'cancelled') {
                $this->closePendingContractLocked($lockedContract);

                return ['state' => 'cancelled'];
            }

            if (
                $lockedOperation->status !== 'pending'
                || $lockedOperation->attempts > 0
            ) {
                throw new DomainException(
                    'El intento de pago requiere reconciliación antes de cancelarse.'
                );
            }

            $lockedOperation->update([
                'status' => 'cancelled',
                'available_at' => null,
                'locked_at' => null,
                'lock_token' => null,
                'last_error' => null,
            ]);

            $this->closePendingContractLocked($lockedContract);

            return ['state' => 'cancelled'];
        });
    }

    public function pendingContract(Tenant $tenant): ?PlanSubscription
    {
        return $tenant->planSubscriptions()
            ->where('pending_tenant_id', $tenant->id)
            ->first();
    }

    public function checkoutOperation(PlanSubscription $contract): ?BillingOutbox
    {
        return BillingOutbox::query()
            ->where('kind', 'checkout')
            ->where('payload->contract_id', $contract->id)
            ->latest('id')
            ->first();
    }

    public function pendingSummary(PlanSubscription $contract): array
    {
        $operation = $this->checkoutOperation($contract);
        $result = $operation?->result ?? [];
        $state = $this->stateFromResult($operation, $result);

        return [
            'name' => $contract->version->name,
            'status' => $contract->status->value,
            'plan' => $contract->version->plan->code,
            'offer_id' => $contract->price->public_id,
            'interval' => $contract->price->interval,
            'checkout_status' => $result['checkout_status'] ?? null,
            'payment_status' => $result['payment_status'] ?? null,
            'state' => $state,
            'can_resume' => $state === 'awaiting_customer',
            'can_cancel' => in_array($state, ['awaiting_customer', 'reconciliation_required'], true),
            'message' => $this->messageForState($state),
        ];
    }

    private function contract(BillingOutbox $operation): PlanSubscription
    {
        $contractId = $operation->payload['contract_id'] ?? null;

        if (! $contractId) {
            throw new DomainException('La operación no contiene contrato.');
        }

        return PlanSubscription::query()->findOrFail($contractId);
    }

    private function assertCheckoutBelongsToContract(Session $session, PlanSubscription $contract): void
    {
        if (
            $session->customer !== $contract->tenant->stripe_id
            || (bool) $session->livemode !== (bool) config('billing.livemode', false)
            || data_get($session, 'metadata.contract_public_id') !== $contract->public_id
        ) {
            throw new DomainException('La sesión de pago no corresponde al contrato.');
        }
    }

    private function storeCheckoutState(
        BillingOutbox $operation,
        PlanSubscription $contract,
        Session $session
    ): void {
        $operation->refresh();
        $result = $operation->result ?? [];

        $operation->update([
            'result' => [
                ...$result,
                'session_id' => $session->id,
                'checkout_status' => $session->status,
                'payment_status' => $session->payment_status,
                'subscription_id' => $session->subscription
                    ? (is_string($session->subscription) ? $session->subscription : $session->subscription->id)
                    : null,
                'state' => $this->stateForSession($session, $contract),
            ],
        ]);
    }

    private function stateForSession(Session $session, PlanSubscription $contract): string
    {
        if ($session->status === 'open' && $session->payment_status === 'unpaid') {
            return 'awaiting_customer';
        }

        if ($session->status === 'expired') {
            return 'expired';
        }

        if ($session->status === 'complete' && $session->payment_status !== 'paid') {
            return 'awaiting_payment';
        }

        if ($session->status === 'complete' && $session->subscription) {
            return 'provisioning';
        }

        return 'reconciliation_required';
    }

    private function stateFromResult(?BillingOutbox $operation, array $result): string
    {
        if (isset($result['state']) && is_string($result['state'])) {
            return $result['state'] === 'expired'
                ? 'reconciliation_required'
                : $result['state'];
        }

        if (! $operation) {
            return 'reconciliation_required';
        }

        if (in_array($operation->status, ['pending', 'processing'], true)) {
            return 'provisioning';
        }

        if ($operation->status === 'reconciliation_required') {
            return 'reconciliation_required';
        }

        if (
            $operation->status === 'completed'
            && isset($result['url'], $result['session_id'])
            && ! isset($result['subscription_id'])
        ) {
            return 'awaiting_customer';
        }

        return 'reconciliation_required';
    }

    private function messageForState(string $state): string
    {
        return match ($state) {
            'awaiting_customer' => 'El pago todavía no fue realizado.',
            'awaiting_payment' => 'Stripe recibió el checkout y falta confirmar el pago.',
            'provisioning' => 'El pago se está reconciliando con el contrato y sus extras.',
            default => 'El estado necesita reconciliación antes de continuar.',
        };
    }

    private function closePendingContract(PlanSubscription $contract): void
    {
        DB::transaction(function () use ($contract): void {
            Tenant::whereKey($contract->tenant_id)->lockForUpdate()->firstOrFail();
            $locked = PlanSubscription::query()
                ->whereKey($contract->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->closePendingContractLocked($locked);
        });
    }

    private function closePendingContractLocked(
        PlanSubscription $contract,
    ): void {
        if (
            $contract->current_tenant_id
            || $contract->ended_at
            || $contract->billingLinks()->exists()
        ) {
            return;
        }

        $contract->update([
            'pending_tenant_id' => null,
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
            'ended_at' => now(),
        ]);

        $contract->addons()
            ->where('status', 'pending')
            ->update([
                'status' => 'ended',
                'ends_at' => now(),
            ]);
    }

    public function run(Tenant $tenant, bool $remote = true): bool
    {
        return app(CurrentTenant::class)->run($tenant, function () use ($tenant, $remote) {
            $ok = true;
            $attempt = function (callable $action) use (&$ok) {
                try {
                    $action();
                } catch (Throwable $e) {
                    $ok = false;
                    // Provider messages may contain credentials or payment URLs.
                    Log::warning('Billing reconciliation failed', ['exception' => get_class($e)]);
                }
            };

            if ($remote) {
                foreach ($tenant->planSubscriptions()->whereNotNull('pending_tenant_id')->get() as $pending) {
                    $op = $this->checkoutOperation($pending);

                    if (isset($op?->result['session_id'])) {
                        $attempt(fn () => $this->checkout($op));
                    }
                }

                $activeContractIds = $tenant->planSubscriptions()
                    ->whereNull('ended_at')
                    ->pluck('id');

                $links = BillingSubscriptionLink::query()
                    ->whereIn('plan_subscription_id', $activeContractIds)
                    ->orderBy('role')
                    ->get();

                $verification = [];

                foreach ($links as $link) {
                    $verification[$link->plan_subscription_id] ??= true;

                    try {
                        $this->sync->sync(
                            $tenant,
                            $this->reader->subscription(
                                $tenant,
                                $link->stripe_subscription_id,
                            )
                        );
                    } catch (Throwable $e) {
                        $ok = false;
                        $verification[$link->plan_subscription_id] = false;
                        Log::warning('Billing reconciliation failed', [
                            'exception' => get_class($e),
                        ]);
                    }
                }

                $current = $tenant->planSubscriptions()
                    ->where('current_tenant_id', $tenant->id)
                    ->first();

                if ($current && $current->price->amount > 0) {
                    $expectedLinks = $current->billingLinks()->count();
                    $successful = $expectedLinks === 2
                        && ($verification[$current->id] ?? false);

                    $this->billingAccess->recordVerification(
                        $current,
                        $successful,
                    );
                }
            }

            foreach (BillingOutbox::where(fn ($q) => $q->where(fn ($p) => $p->where('status', 'pending')->where('available_at', '<=', now()))->orWhere(fn ($p) => $p->where('status', 'processing')->where('locked_at', '<', now()->subMinutes(10))))->orderBy('id')->limit(100)->get() as $op) {
                $attempt(fn () => app(BillingProcessor::class)->run($op));
            }

            $this->billingAccess->applyExpiredGrace($tenant);

            return $ok;
        });
    }
}
