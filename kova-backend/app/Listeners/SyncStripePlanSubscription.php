<?php

namespace App\Listeners;

use App\Domain\Billing\SyncStripeSubscriptionService;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\AuditEvent;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Events\WebhookHandled;
use Stripe\StripeClient;

class SyncStripePlanSubscription
{
    private const HANDLED_EVENTS = [
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'invoice.payment_failed',
        'invoice.payment_succeeded',
        'chargeback.created',
        'chargeback.closed',
    ];

    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly SyncStripeSubscriptionService $syncService,
        private readonly StripeClient $stripe,
    ) {}

    public function handle(WebhookHandled $event): void
    {
        $eventType = $event->payload['type'] ?? null;

        if (! in_array($eventType, self::HANDLED_EVENTS, true)) {
            return;
        }

        // --- Idempotencia: evitar procesar el mismo evento dos veces ---
        $eventId = $event->payload['id'] ?? null;

        if ($eventId !== null) {
            $alreadyProcessed = DB::table('processed_stripe_events')
                ->where('stripe_event_id', $eventId)
                ->exists();

            if ($alreadyProcessed) {
                return;
            }
        }

        $this->processEvent($eventType, $event->payload);

        // Marcar como procesado después de ejecutar exitosamente
        if ($eventId !== null) {
            DB::table('processed_stripe_events')->insert([
                'stripe_event_id' => $eventId,
                'processed_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function processEvent(string $eventType, array $payload): void
    {
        match ($eventType) {
            'customer.subscription.created',
            'customer.subscription.updated'
            => $this->syncSubscription($payload),
            'customer.subscription.deleted' => $this->handleSubscriptionDeleted($payload),
            'invoice.payment_failed' => $this->handlePaymentFailed($payload),
            'invoice.payment_succeeded' => $this->handlePaymentSucceeded($payload),
            'chargeback.created' => $this->handleChargebackCreated($payload),
            'chargeback.closed' => $this->handleChargebackClosed($payload),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncSubscription(array $payload): void
    {
        $data = $payload['data']['object'] ?? null;

        if (! is_array($data) || ! isset($data['id'], $data['customer'])) {
            return;
        }

        $tenant = Tenant::query()
            ->where('stripe_id', $data['customer'])
            ->first();

        if (! $tenant) {
            return;
        }

        $eventCreatedAt = $payload['created'] ?? null;

        $this->currentTenant->run($tenant, function () use ($tenant, $data, $eventCreatedAt): void {
            // Para customer.subscription.updated, re-fetchear la suscripción actual
            // desde Stripe para obtener el estado real, no el snapshot del evento.
            // Esto evita que un webhook retrasado sobrescriba un estado más reciente.
            try {
                $stripeSubscription = $this->stripe->subscriptions->retrieve($data['id']);
            } catch (\Exception) {
                // Si falla la consulta, usar los datos del evento como respaldo
                $stripeSubscription = \Stripe\Subscription::constructFrom($data);
            }

            $this->syncService->sync(
                $tenant,
                $stripeSubscription,
                is_int($eventCreatedAt) ? $eventCreatedAt : null,
            );
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleSubscriptionDeleted(array $payload): void
    {
        $data = $payload['data']['object'] ?? null;

        if (! is_array($data) || ! isset($data['id'], $data['customer'])) {
            return;
        }

        $tenant = Tenant::query()
            ->where('stripe_id', $data['customer'])
            ->first();

        if (! $tenant) {
            return;
        }

        $this->currentTenant->run($tenant, function () use ($tenant, $data): void {
            // Construir objeto Stripe desde el payload para pasarlo al servicio
            $stripeSubscription = \Stripe\Subscription::constructFrom($data);

            $this->syncService->syncDeleted($tenant, $stripeSubscription);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handlePaymentFailed(array $payload): void
    {
        $data = $payload['data']['object'] ?? null;

        if (! is_array($data) || ! isset($data['customer'])) {
            return;
        }

        $tenant = Tenant::query()
            ->where('stripe_id', $data['customer'])
            ->first();

        if (! $tenant) {
            return;
        }

        $this->currentTenant->run($tenant, function () use ($tenant): void {
            DB::transaction(function () use ($tenant): void {
                $subscription = PlanSubscription::query()
                    ->where('tenant_id', $tenant->getKey())
                    ->whereIn('status', [
                        SubscriptionStatus::Active->value,
                        SubscriptionStatus::Trial->value,
                    ])
                    ->latest('starts_at')
                    ->first();

                if ($subscription && $subscription->status !== SubscriptionStatus::PastDue) {
                    $subscription->update([
                        'status' => SubscriptionStatus::PastDue,
                    ]);
                }
            });
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handlePaymentSucceeded(array $payload): void
    {
        $data = $payload['data']['object'] ?? null;

        if (! is_array($data) || ! isset($data['customer'])) {
            return;
        }

        $tenant = Tenant::query()
            ->where('stripe_id', $data['customer'])
            ->first();

        if (! $tenant) {
            return;
        }

        $this->currentTenant->run($tenant, function () use ($tenant): void {
            DB::transaction(function () use ($tenant): void {
                $subscription = PlanSubscription::query()
                    ->where('tenant_id', $tenant->getKey())
                    ->where('status', SubscriptionStatus::PastDue->value)
                    ->latest('starts_at')
                    ->first();

                if ($subscription) {
                    $subscription->update([
                        'status' => SubscriptionStatus::Active,
                    ]);
                }
            });
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleChargebackCreated(array $payload): void
    {
        $data = $payload['data']['object'] ?? null;

        if (! is_array($data) || ! isset($data['customer'])) {
            return;
        }

        $tenant = Tenant::query()
            ->where('stripe_id', $data['customer'])
            ->first();

        if (! $tenant) {
            return;
        }

        $this->currentTenant->run($tenant, function () use ($tenant): void {
            DB::transaction(function () use ($tenant): void {
                $tenant->update([
                    'status' => TenantStatus::Suspended,
                ]);

                AuditEvent::query()->create([
                    'actor_user_id' => null,
                    'action' => 'billing.chargeback_created',
                    'auditable_type' => Tenant::class,
                    'auditable_public_id' => $tenant->public_id,
                    'old_values' => ['status' => TenantStatus::Active->value],
                    'new_values' => ['status' => TenantStatus::Suspended->value],
                    'request_id' => request()->attributes->get('request_id'),
                    'ip_address' => request()->ip(),
                    'user_agent' => mb_substr((string) request()->userAgent(), 0, 1024),
                ]);
            });
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleChargebackClosed(array $payload): void
    {
        $data = $payload['data']['object'] ?? null;

        if (! is_array($data) || ! isset($data['customer'])) {
            return;
        }

        $tenant = Tenant::query()
            ->where('stripe_id', $data['customer'])
            ->first();

        if (! $tenant) {
            return;
        }

        $this->currentTenant->run($tenant, function () use ($data, $tenant): void {
            DB::transaction(function () use ($data, $tenant): void {
                $status = $data['status'] ?? '';

                if ($status === 'won') {
                    $tenant->update([
                        'status' => TenantStatus::Active,
                    ]);

                    AuditEvent::query()->create([
                        'actor_user_id' => null,
                        'action' => 'billing.chargeback_won',
                        'auditable_type' => Tenant::class,
                        'auditable_public_id' => $tenant->public_id,
                        'old_values' => ['status' => TenantStatus::Suspended->value],
                        'new_values' => ['status' => TenantStatus::Active->value],
                        'request_id' => request()->attributes->get('request_id'),
                        'ip_address' => request()->ip(),
                        'user_agent' => mb_substr((string) request()->userAgent(), 0, 1024),
                    ]);
                }
            });
        });
    }
}
