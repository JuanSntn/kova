<?php

namespace App\Services\Subscriptions;

use App\Models\AddonPrice;
use App\Models\BillingOutbox;
use App\Models\PlanPrice;
use App\Models\PlanSubscription;
use App\Models\SubscriptionAddon;
use App\Models\SubscriptionChange;
use App\Models\Tenant;
use App\Models\UsageEvent;
use App\Models\UsageReservation;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\StripeClient;
use Throwable;

final class BillingProcessor
{
    public function __construct(
        private StripeClient $stripe,
        private CatalogService $catalog,
        private ContractService $contracts,
        private StripeContractSynchronizer $sync,
    ) {}

    public function run(BillingOutbox $op): array
    {
        $lockToken = (string) Str::uuid();

        $claimed = DB::transaction(function () use ($op, $lockToken) {
            $fresh = BillingOutbox::query()->whereKey($op->id)->lockForUpdate()->firstOrFail();

            if (in_array($fresh->status, ['completed', 'cancelled'], true)) {
                return false;
            }

            if ($fresh->status === 'reconciliation_required') {
                return 'reconcile';
            }

            if ($fresh->locked_at && $fresh->locked_at->gt(now()->subMinutes(10))) {
                throw new DomainException('La operación sigue en proceso.');
            }

            if ($fresh->attempts && $fresh->created_at->lt(now()->subHours(23))) {
                $fresh->update([
                    'status' => 'reconciliation_required',
                ]);

                return 'reconcile';
            }

            $claim = BillingOutbox::query()
                ->whereKey($fresh->id);

            if ($fresh->status === 'processing') {
                $claim
                    ->where('status', 'processing')
                    ->where(
                        'locked_at',
                        '<',
                        now()->subMinutes(10),
                    );
            } else {
                $claim->where('status', $fresh->status);
            }

            $updated = $claim->update([
                'status' => 'processing',
                'locked_at' => now(),
                'lock_token' => $lockToken,
                'attempts' => DB::raw('attempts + 1'),
            ]);

            if ($updated !== 1) {
                throw new DomainException(
                    'La operación fue reclamada por otro worker.'
                );
            }

            return true;
        });

        if ($claimed === 'reconcile') {
            throw new DomainException('La operación requiere reconciliación antes de volver a cobrar.');
        }

        if (! $claimed) {
            return $op->refresh()->result ?? [];
        }

        $op->refresh();

        try {
            $result = match ($op->kind) {
                'checkout' => $this->checkout($op),
                'provision_extras' => $this->extras($op),
                'upgrade_plan' => $this->upgradePlan($op),
                'transition_extras' => $this->transitionExtras($op),
                'schedule_plan', 'schedule_addon' => $this->schedule($op),
                'cancel_change' => $this->cancelChange($op),
                'cancel', 'reactivate' => $this->cancellation($op),
                'add_addon' => $this->addAddon($op),
                'meter' => $this->meter($op),
                'close_extras' => $this->closeExtras($op),

                default => throw new DomainException('Operación de cobro desconocida.'),
            };

            $finalized = $this->completeLease(
                $op->id,
                $lockToken,
                $result,
            );

            if (! $finalized) {
                throw new DomainException(
                    'La operación perdió su lease y requiere reconciliación.'
                );
            }

            return $result;
        } catch (Throwable $e) {
            $released = $this->releaseLease(
                $op->id,
                $lockToken,
                $e,
            );

            if (
                $released
                && $op->kind === 'meter'
                && isset($op->payload['event_id'])
            ) {
                UsageEvent::query()
                    ->whereKey($op->payload['event_id'])
                    ->update(['metering_status' => 'failed']);
            }

            throw $e;
        }
    }

    private function completeLease(
        int $operationId,
        string $lockToken,
        array $result,
    ): bool {
        return BillingOutbox::query()
            ->whereKey($operationId)
            ->where('status', 'processing')
            ->where('lock_token', $lockToken)
            ->update([
                'status' => 'completed',
                'result' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR,
                ),
                'locked_at' => null,
                'lock_token' => null,
                'last_error' => null,
            ]) === 1;
    }

    private function releaseLease(
        int $operationId,
        string $lockToken,
        Throwable $exception,
    ): bool {
        $operation = BillingOutbox::query()->findOrFail($operationId);
        $delay = min(
            3600,
            30 * (2 ** min($operation->attempts, 7)),
        );

        return BillingOutbox::query()
            ->whereKey($operationId)
            ->where('status', 'processing')
            ->where('lock_token', $lockToken)
            ->update([
                'status' => 'pending',
                'locked_at' => null,
                'lock_token' => null,
                'last_error' => mb_substr(
                    $exception->getMessage(),
                    0,
                    2000,
                ),
                'available_at' => now()->addSeconds($delay),
            ]) === 1;
    }

    private function options(BillingOutbox $op, string $step): array
    {
        return [
            'idempotency_key' => hash('sha256', $op->tenant->public_id.':'.$op->operation_key.':'.$step),
        ];
    }

    private function contract(BillingOutbox $op): PlanSubscription
    {
        $contractId = $op->payload['contract_id'] ?? null;

        if (! $contractId) {
            throw new DomainException('La operación no contiene un contrato.');
        }

        return PlanSubscription::query()->findOrFail($contractId);
    }

    private function taxes(): array
    {
        $tax = config('billing.tax_rate');

        if (! $tax) {
            throw new DomainException('Falta configurar el impuesto del cobro.');
        }

        return [$tax];
    }

    private function url(Tenant $tenant): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/'.$tenant->slug.'/billing';
    }

    private function objectId(mixed $value): ?string
    {
        return is_string($value)
            ? $value
            : data_get($value, 'id');
    }

    private function checkout(BillingOutbox $op): array
    {
        $subscription = $this->contract($op);

        $tenant = $subscription->tenant;

        if (! $tenant->stripe_id) {
            $customer = $this->stripe->customers->create(
                [
                    'name' => $tenant->name,
                    'email' => $op->payload['email'],
                    'metadata' => [
                        'tenant_public_id' => $tenant->public_id,
                    ],
                ],
                $this->options($op, 'customer')
            );

            $tenant->forceFill([
                'stripe_id' => $customer->id,
            ])->save();
        }

        $mapping = $this->catalog->mapping($subscription->price);

        $session = $this->stripe->checkout->sessions->create(
            [
                'metadata' => [
                    'operation_key' => hash('sha256', $op->tenant->public_id.':'.$op->operation_key),
                    'contract_public_id' => $subscription->public_id,
                ],
                'integration_identifier' => $this->checkoutIntegrationIdentifier($op),
                'mode' => 'subscription',
                'customer' => $tenant->stripe_id,
                'line_items' => [
                    [
                        'price' => $mapping->stripe_price_id,
                        'quantity' => 1,
                    ],
                ],
                'success_url' => $this->url($tenant).'?checkout=success',
                'cancel_url' => $this->url($tenant).'?checkout=cancelled',
                'subscription_data' => [
                    'default_tax_rates' => $this->taxes(),
                    'metadata' => [
                        'contract_public_id' => $subscription->public_id,
                        'tenant_public_id' => $tenant->public_id,
                        'billing_role' => 'base',
                        'type' => 'default',
                    ],
                ],
            ],
            $this->options($op, 'session')
        );

        return [
            'url' => $session->url,
            'session_id' => $session->id,
            'checkout_status' => $session->status ?? 'open',
            'payment_status' => $session->payment_status ?? 'unpaid',
            'state' => 'awaiting_customer',
        ];
    }

    private function extras(BillingOutbox $op): array
    {
        $subscription = $this->contract($op);

        $base = $subscription->billingLinks()->where('role', 'base')->firstOrFail();

        $remote = $this->stripe->subscriptions->retrieve(
            $base->stripe_subscription_id,
            [
                'expand' => [
                    'latest_invoice',
                ],
            ]
        );

        if ($remote->status !== 'active' || data_get($remote, 'latest_invoice.status') !== 'paid') {
            throw new DomainException('El pago del plan aún no está confirmado.');
        }

        $items = [];

        foreach ($subscription->addons as $addon) {
            $items[] = [
                'price' => $this->catalog->mapping($addon->price)->stripe_price_id,
            ];
        }

        if ($items === []) {
            throw new DomainException(
                'El plan pagado requiere configurar el complemento mensual de consumo.'
            );
        }

        $anchor = CarbonImmutable::instance($subscription->usage_anchor_at);

        $extras = $this->stripe->subscriptions->create(
            [
                'customer' => $subscription->tenant->stripe_id,
                'items' => $items,
                'default_tax_rates' => $this->taxes(),
                ...(
                    $remote->default_payment_method
                    ? [
                        'default_payment_method' => $this->objectId($remote->default_payment_method),
                    ]
                    : []
                ),
                'billing_cycle_anchor_config' => [
                    'day_of_month' => $anchor->day,
                    'hour' => $anchor->hour,
                    'minute' => $anchor->minute,
                    'second' => $anchor->second,
                ],
                'proration_behavior' => 'none',
                'metadata' => [
                    'contract_public_id' => $subscription->public_id,
                    'tenant_public_id' => $subscription->tenant->public_id,
                    'billing_role' => 'extras',
                    'type' => 'extras',
                ],
            ],
            $this->options($op, 'extras')
        );

        $this->sync->sync($subscription->tenant, $extras);

        return [
            'stripe_subscription_id' => $extras->id,
        ];
    }

    private function checkoutIntegrationIdentifier(BillingOutbox $op): string
    {
        $digest = hash('sha256', $op->tenant->public_id.':'.$op->operation_key, true);
        $suffix = '';

        for ($index = 0; $index < 8; $index++) {
            $suffix .= chr(97 + (ord($digest[$index]) % 26));
        }

        return 'kova_'.$suffix;
    }

    private function cancellation(BillingOutbox $op): array
    {
        $subscription = $this->contract($op);

        if ($subscription->ended_at) {
            throw new DomainException('El contrato ya terminó.');
        }

        $cancel = $op->kind === 'cancel';

        /*
         * La misma precondición se comprueba antes de encolar. Se repite
         * aquí para cubrir una carrera donde el cambio se crea mientras
         * este worker reclama la operación.
         */
        $this->contracts->assertNoPendingChange(
            $subscription,
            $cancel ? 'cancel' : 'reactivate',
        );

        foreach ($subscription->billingLinks()->orderBy('role')->get() as $link) {
            $params = $link->role === 'base'
                ? [
                    'cancel_at_period_end' => $cancel,
                ]
                : [
                    'cancel_at' => $cancel
                        ? $subscription->current_period_ends_at->timestamp
                        : '',
                ];

            $this->stripe->subscriptions->update(
                $link->stripe_subscription_id,
                $params,
                $this->options($op, $link->role)
            );
        }

        $subscription->update([
            'cancel_at_period_end' => $cancel,
            'cancellation_requested_at' => $cancel
                ? now()
                : null,
            'cancellation_branch_ids' => $cancel
                ? ($op->payload['retained_branch_ids'] ?? [])
                : null,
            'cancellation_membership_ids' => $cancel
                ? ($op->payload['retained_membership_ids'] ?? [])
                : null,
        ]);

        return [
            'cancel_at_period_end' => $cancel,
        ];
    }

    private function schedule(BillingOutbox $op): array
    {
        $change = SubscriptionChange::query()->findOrFail(
            $op->payload['change_id']
        );

        $subscription = PlanSubscription::query()->findOrFail($change->plan_subscription_id);

        if ($change->status === 'cancelled') {
            return [];
        }

        if ($change->change_mode !== 'period_end') {
            throw new DomainException('La operación no corresponde a un cambio programado.');
        }

        if ($change->effective_at->lte(now())) {
            throw new DomainException('La fecha del cambio pasó: reconciliación requerida.');
        }

        if ($change->kind === 'plan') {
            $target = PlanPrice::query()->findOrFail($change->target_plan_price_id);

            if ($target->amount === 0) {
                foreach ($subscription->billingLinks()->get() as $component) {
                    $params = $component->role === 'base'
                        ? [
                            'cancel_at_period_end' => true,
                        ]
                        : [
                            'cancel_at' => $change->effective_at->timestamp,
                        ];

                    $this->stripe->subscriptions->update(
                        $component->stripe_subscription_id,
                        $params,
                        $this->options($op, 'free-'.$component->role)
                    );
                }

                $subscription->update([
                    'cancel_at_period_end' => true,
                    'cancellation_requested_at' => now(),
                ]);

                return [
                    'free_transition' => true,
                    'contract_id' => $subscription->id,
                ];
            }
        }

        $role = $change->kind === 'plan'
            ? 'base'
            : 'extras';

        $link = $subscription->billingLinks()->where('role', $role)->firstOrFail();

        $remote = $this->stripe->subscriptions->retrieve($link->stripe_subscription_id);

        $scheduleId = $this->objectId($remote->schedule);

        if (! $scheduleId) {
            $scheduleId = $this->stripe->subscriptionSchedules->create(
                [
                    'from_subscription' => $remote->id,
                ],
                $this->options($op, 'create-schedule')
            )->id;
        }

        $schedule = $this->stripe->subscriptionSchedules->retrieve($scheduleId);

        $current = [];

        foreach ($remote->items->data as $item) {
            $entry = [
                'price' => $item->price->id,
            ];

            if (($item->price->recurring->usage_type ?? '') !== 'metered') {
                $entry['quantity'] = $item->quantity ?? 1;
            }

            $current[] = $entry;
        }

        $next = $current;

        $target = null;

        if ($change->kind === 'plan') {
            $target = PlanPrice::query()->findOrFail($change->target_plan_price_id);

            $next = [
                [
                    'price' => $this->catalog->mapping($target)->stripe_price_id,
                    'quantity' => 1,
                ],
            ];
        } else {
            $addon = SubscriptionAddon::query()->findOrFail($change->subscription_addon_id);

            $stripePriceId = $this->catalog->mapping($addon->price)->stripe_price_id;

            $next = [];

            foreach ($current as $entry) {
                if ($entry['price'] === $stripePriceId) {
                    if ($change->quantity > 0) {
                        $next[] = [
                            'price' => $stripePriceId,
                            'quantity' => $change->quantity,
                        ];
                    }

                    continue;
                }

                $next[] = $entry;
            }
        }

        $this->setPhases(
            $op,
            $scheduleId,
            $schedule->current_phase->start_date,
            $change->effective_at->timestamp,
            $current,
            $next,
            'base'
        );

        $result = [
            'schedule_id' => $scheduleId,
        ];

        if ($change->kind === 'plan') {
            if (! $target) {
                throw new DomainException('No se pudo resolver la oferta destino.');
            }

            $extrasLink = $subscription->billingLinks()->where('role', 'extras')->firstOrFail();

            $extras = $this->stripe->subscriptions->retrieve($extrasLink->stripe_subscription_id);

            $extrasScheduleId = $this->objectId($extras->schedule);

            if (! $extrasScheduleId) {
                $extrasScheduleId = $this->stripe->subscriptionSchedules->create(
                    [
                        'from_subscription' => $extras->id,
                    ],
                    $this->options($op, 'create-extras-schedule')
                )->id;
            }

            $extrasSchedule = $this->stripe->subscriptionSchedules->retrieve($extrasScheduleId);

            $oldExtras = [];

            foreach ($extras->items->data as $item) {
                $entry = [
                    'price' => $item->price->id,
                ];

                if (($item->price->recurring->usage_type ?? '') !== 'metered') {
                    $entry['quantity'] = $item->quantity ?? 1;
                }

                $oldExtras[] = $entry;
            }

            $newExtras = [];

            if ($target->plan_version_id === $subscription->plan_version_id) {
                $newExtras = $oldExtras;
            } else {
                foreach ($target->version->availableAddons as $addonVersion) {
                    if (! $addonVersion->pivot->automatic) {
                        continue;
                    }

                    $addonPrice = $addonVersion->prices->firstWhere('interval', 'month');

                    if (! $addonPrice) {
                        throw new DomainException(
                            'El complemento automático del nuevo plan no tiene precio mensual.'
                        );
                    }

                    $newExtras[] = [
                        'price' => $this->catalog->mapping($addonPrice)->stripe_price_id,
                    ];
                }
            }

            if ($newExtras === []) {
                throw new DomainException('El nuevo plan no tiene su consumo mensual configurado.');
            }

            $this->setPhases(
                $op,
                $extrasScheduleId,
                $extrasSchedule->current_phase->start_date,
                $change->effective_at->timestamp,
                $oldExtras,
                $newExtras,
                'extras'
            );

            $result['extras_schedule_id'] = $extrasScheduleId;
        }

        return $result;
    }

    private function setPhases(
        BillingOutbox $op,
        string $id,
        int $start,
        int $end,
        array $current,
        array $next,
        string $step,
    ): void {
        $this->stripe->subscriptionSchedules->update(
            $id,
            [
                'end_behavior' => 'release',
                'phases' => [
                    [
                        'start_date' => $start,
                        'end_date' => $end,
                        'items' => $current,
                        'default_tax_rates' => $this->taxes(),
                        'proration_behavior' => 'none',
                    ],
                    [
                        'start_date' => $end,
                        'items' => $next,
                        'default_tax_rates' => $this->taxes(),
                        'proration_behavior' => 'none',
                        'iterations' => 1,
                    ],
                ],
            ],
            $this->options($op, 'update-schedule-'.$step)
        );
    }

    private function cancelChange(BillingOutbox $op): array
    {
        $change = SubscriptionChange::query()->findOrFail(
            $op->payload['change_id']
        );

        if ($change->status === 'cancelled') {
            return [];
        }

        if ($change->change_mode !== 'period_end') {
            throw new DomainException('Sólo los cambios programados pueden cancelarse por este flujo.');
        }

        if ($change->effective_at->lte(now())) {
            throw new DomainException('El cambio ya está en ejecución.');
        }

        $kind = $change->kind === 'plan'
            ? 'schedule_plan'
            : 'schedule_addon';

        $source = BillingOutbox::query()->where(
            'kind',
            $kind
        )->where(
            'payload->change_id',
            $change->id
        )->firstOrFail();

        if ($source->status !== 'completed') {
            throw new DomainException('Espera a que termine de programarse el cambio.');
        }

        if ($source->result['free_transition'] ?? false) {
            $subscription = PlanSubscription::query()->findOrFail(
                $source->result['contract_id']
            );

            foreach ($subscription->billingLinks()->get() as $link) {
                $params = $link->role === 'base'
                    ? [
                        'cancel_at_period_end' => false,
                    ]
                    : [
                        'cancel_at' => '',
                    ];

                $this->stripe->subscriptions->update(
                    $link->stripe_subscription_id,
                    $params,
                    $this->options($op, 'undo-free-'.$link->role)
                );
            }

            $subscription->update([
                'cancel_at_period_end' => false,
                'cancellation_requested_at' => null,
                'cancellation_branch_ids' => null,
                'cancellation_membership_ids' => null,
            ]);
        }

        foreach (['schedule_id', 'extras_schedule_id'] as $field) {
            $scheduleId = $source->result[$field] ?? null;

            if (! $scheduleId) {
                continue;
            }

            $this->stripe->subscriptionSchedules->release(
                $scheduleId,
                [],
                $this->options($op, 'release-'.$field)
            );
        }

        $change->update([
            'status' => 'cancelled',
            'completed_at' => now(),
        ]);

        return [];
    }

    private function addAddon(BillingOutbox $op): array
    {
        $subscription = $this->contract($op);

        $price = AddonPrice::query()->findOrFail(
            $op->payload['addon_price_id']
        );

        if (
            $subscription->cancel_at_period_end || $subscription->ended_at || ! $subscription->current_tenant_id
        ) {
            throw new DomainException('El contrato ya no permite ampliar cupos.');
        }

        $link = $subscription->billingLinks()->where('role', 'extras')->firstOrFail();

        $mapping = $this->catalog->mapping($price);

        $existing = $subscription->addons()->where(
            'addon_price_id',
            $price->id
        )->where(
            'status',
            'active'
        )->first();

        $item = [
            'price' => $mapping->stripe_price_id,
            'quantity' => $op->payload['quantity'],
        ];

        if ($existing?->stripe_item_id) {
            $item['id'] = $existing->stripe_item_id;
        }

        $remote = $this->stripe->subscriptions->update(
            $link->stripe_subscription_id,
            [
                'items' => [
                    $item,
                ],
                'billing_cycle_anchor' => 'unchanged',
                'payment_behavior' => 'pending_if_incomplete',
                'proration_behavior' => 'always_invoice',
                'expand' => [
                    'latest_invoice',
                ],
            ],
            $this->options($op, 'update')
        );

        $this->sync->sync($subscription->tenant, $remote);

        return [
            'status' => $remote->pending_update
                ? 'awaiting_payment'
                : 'confirmed',
            'invoice_url' => data_get($remote, 'latest_invoice.hosted_invoice_url'),
        ];
    }

    private function upgradePlan(BillingOutbox $op): array
    {
        $change = SubscriptionChange::query()
            ->findOrFail($op->payload['change_id']);

        if ($change->status === 'cancelled') {
            return ['status' => 'cancelled'];
        }

        if ($change->status === 'completed') {
            return ['status' => 'confirmed'];
        }

        if ($change->change_mode !== 'immediate') {
            throw new DomainException(
                'La operación no corresponde a un upgrade inmediato.'
            );
        }

        $source = PlanSubscription::query()
            ->findOrFail($change->plan_subscription_id);

        $target = PlanPrice::query()
            ->findOrFail($change->target_plan_price_id);

        $baseLink = $source->billingLinks()
            ->where('role', 'base')
            ->firstOrFail();

        $subscriptionId = $baseLink->stripe_subscription_id;

        $remote = $this->stripe->subscriptions->retrieve(
            $subscriptionId,
            [
                'expand' => ['latest_invoice'],
            ]
        );

        if ($this->objectId($remote->schedule ?? null)) {
            throw new DomainException(
                'La suscripción tiene un cambio programado en Stripe. Cancela o reconcilia ese cambio antes del upgrade.'
            );
        }

        /*
     * Si ya existe un pending_update, NO sincronizamos todavía.
     * Stripe aún no ha aplicado el nuevo Price.
     */
        if ($remote->pending_update ?? null) {
            return [
                'status' => 'awaiting_payment',
                'invoice_id' => $this->objectId(
                    $remote->latest_invoice ?? null
                ),
                'invoice_url' => data_get(
                    $remote,
                    'latest_invoice.hosted_invoice_url'
                ),
            ];
        }

        $mapping = $this->catalog->mapping($target);
        $targetPriceId = $mapping->stripe_price_id;
        $items = $remote->items->data ?? [];
        $baseItem = null;

        foreach ($items as $item) {
            if (
                data_get($item, 'price.recurring.usage_type')
                !== 'metered'
            ) {
                $baseItem = $item;
                break;
            }
        }

        $baseItem ??= $items[0] ?? null;

        if (! $baseItem) {
            throw new DomainException(
                'La suscripción no tiene un item base reconocible.'
            );
        }

        $currentPriceId = data_get($baseItem, 'price.id');

        /*
     * Solamente hacemos el cambio remoto si Stripe
     * todavía tiene el Price anterior.
     */
        if ($currentPriceId !== $targetPriceId) {
            $remote = $this->stripe->subscriptions->update(
                $subscriptionId,
                [
                    'items' => [
                        [
                            'id' => $baseItem->id,
                            'price' => $targetPriceId,
                            'quantity' => $baseItem->quantity ?? 1,
                        ],
                    ],
                    'proration_behavior' => 'always_invoice',
                    'payment_behavior' => 'pending_if_incomplete',
                    'expand' => ['latest_invoice'],
                ],
                $this->options($op, 'base-upgrade')
            );
        }

        /*
     * pending_if_incomplete puede dejar el cambio pendiente de pago.
     * En ese estado Stripe todavía NO es autoridad para decir
     * que Restaurant está activo.
     *
     * Por eso este check va ANTES de sync().
     */
        if ($remote->pending_update ?? null) {
            return [
                'status' => 'awaiting_payment',
                'invoice_id' => $this->objectId(
                    $remote->latest_invoice ?? null
                ),
                'invoice_url' => data_get(
                    $remote,
                    'latest_invoice.hosted_invoice_url'
                ),
            ];
        }

        /*
     * A partir de aquí Stripe ya aplicó realmente
     * el Price destino.
     */
        $this->sync->sync(
            $source->tenant,
            $remote
        );

        /*
     * El synchronizer crea transition_extras
     * cuando detecta el nuevo contrato.
     */
        $extrasOperation = BillingOutbox::query()
            ->where('kind', 'transition_extras')
            ->where('payload->change_id', $change->id)
            ->first();

        if (
            $extrasOperation
            && $extrasOperation->status !== 'completed'
        ) {
            $extrasResult = $this->run($extrasOperation);

            return [
                'status' => $extrasResult['status'] ?? 'processing',
                'invoice_id' => $this->objectId(
                    $remote->latest_invoice ?? null
                ),
                'invoice_url' => data_get(
                    $remote,
                    'latest_invoice.hosted_invoice_url'
                ),
            ];
        }

        return [
            'status' => $change->refresh()->status === 'completed'
                ? 'confirmed'
                : 'processing',
            'invoice_id' => $this->objectId(
                $remote->latest_invoice ?? null
            ),
            'invoice_url' => data_get(
                $remote,
                'latest_invoice.hosted_invoice_url'
            ),
        ];
    }

    private function meter(BillingOutbox $op): array
    {
        $payload = $op->payload;

        $event = $this->stripe->billing->meterEvents->create(
            [
                'event_name' => $payload['event_name'],
                'identifier' => $payload['identifier'] ?? hash('sha256', $op->tenant->public_id.':'.$op->operation_key),
                'timestamp' => $payload['timestamp'],
                'payload' => [
                    'stripe_customer_id' => $op->tenant->stripe_id,
                    'value' => (string) $payload['quantity'],
                ],
            ],
            $this->options($op, 'meter')
        );

        if (isset($payload['event_id'])) {
            UsageEvent::query()->whereKey($payload['event_id'])->update(['metering_status' => 'reported', 'metered_at' => now()]);
        }

        return [
            'identifier' => $event->identifier,
        ];
    }

    private function closeExtras(BillingOutbox $op): array
    {
        $subscription = $this->contract($op);

        $periodIds = $subscription->usagePeriods()->pluck('id');

        $unsettledEvents = UsageEvent::query()
            ->whereIn('usage_period_id', $periodIds)
            ->where('is_overage', true)
            ->whereIn('metering_status', ['pending', 'failed'])
            ->exists();

        $uncertainReservations = UsageReservation::query()
            ->whereIn('usage_period_id', $periodIds)
            ->whereIn('status', ['reserved', 'reconciliation_required'])
            ->exists();

        if ($unsettledEvents || $uncertainReservations) {
            throw new DomainException(
                'Hay consumo fiscal pendiente de reportar o reconciliar antes de cerrar los extras.'
            );
        }

        $link = $subscription->billingLinks()->where('role', 'extras')->first();

        if (! $link) {
            return [];
        }

        $remote = $this->stripe->subscriptions->retrieve($link->stripe_subscription_id);

        if ($remote->status !== 'canceled') {
            $remote = $this->stripe->subscriptions->cancel(
                $remote->id,
                [
                    'invoice_now' => true,
                    'prorate' => false,
                ],
                $this->options($op, 'close')
            );
        }

        $link->update([
            'status' => 'canceled',
            'past_due_since' => null,
            'last_event_at' => now(),
        ]);

        return [
            'stripe_subscription_id' => $remote->id,
            'status' => 'canceled',
        ];
    }

    private function transitionExtras(BillingOutbox $op): array
    {
        $change = SubscriptionChange::query()->findOrFail(
            $op->payload['change_id']
        );

        if ($change->status === 'cancelled') {
            return [
                'status' => 'cancelled',
            ];
        }

        if ($change->status === 'completed') {
            return [
                'status' => 'confirmed',
            ];
        }

        /*
         * IMPORTANTE:
         *
         * Para un downgrade/cambio programado NO adelantamos extras.
         * Stripe Subscription Schedule debe llegar primero a su fase
         * nueva y el webhook sincronizará ese componente.
         */
        if ($change->change_mode === 'period_end') {
            return [
                'status' => 'waiting_for_schedule',
            ];
        }

        if ($change->change_mode !== 'immediate') {
            throw new DomainException('Modo de transición de extras desconocido.');
        }

        $target = $change->targetContract;

        if (! $target) {
            throw new DomainException('El contrato destino todavía no fue confirmado por Stripe.');
        }

        if ($target->billingLinks()->where('role', 'extras')->exists()) {
            return [
                'status' => $change->refresh()->status === 'completed'
                    ? 'confirmed'
                    : 'processing',
                'contract_id' => $target->public_id,
            ];
        }

        $source = PlanSubscription::query()->findOrFail($change->plan_subscription_id);

        $extrasLink = $source->billingLinks()->where('role', 'extras')->firstOrFail();

        $subscriptionId = $extrasLink->stripe_subscription_id;

        $remote = $this->stripe->subscriptions->retrieve(
            $subscriptionId,
            [
                'expand' => [
                    'latest_invoice',
                ],
            ]
        );

        if ($this->objectId($remote->schedule ?? null)) {
            throw new DomainException(
                'El componente de extras tiene un cambio programado en Stripe. Cancela o reconcilia ese cambio antes del upgrade.'
            );
        }

        $desired = [];

        foreach ($target->version->availableAddons as $addonVersion) {
            if (! $addonVersion->pivot->automatic) {
                continue;
            }

            $price = $addonVersion->prices->firstWhere('interval', 'month');

            if (! $price) {
                throw new DomainException('El complemento automático de destino no tiene precio mensual.');
            }

            $mapping = $this->catalog->mapping($price);

            $desired[$mapping->stripe_price_id] = [
                'metered' => $addonVersion->mode === 'metered',
            ];
        }

        if ($desired === []) {
            throw new DomainException('El nuevo plan no tiene su consumo mensual configurado.');
        }

        $currentItems = $remote->items->data ?? [];

        $updates = [];
        $existingPrices = [];

        foreach ($currentItems as $item) {
            $priceId = data_get($item, 'price.id');

            if (! $priceId) {
                continue;
            }

            $existingPrices[] = $priceId;

            if (isset($desired[$priceId])) {
                continue;
            }

            $updates[] = [
                'id' => $item->id,
                'deleted' => true,
            ];
        }

        foreach ($desired as $priceId => $definition) {
            if (in_array($priceId, $existingPrices, true)) {
                continue;
            }

            $entry = [
                'price' => $priceId,
            ];

            if (! $definition['metered']) {
                $entry['quantity'] = 1;
            }

            $updates[] = $entry;
        }

        $params = [
            'proration_behavior' => 'none',
            'metadata' => [
                'contract_public_id' => $target->public_id,
                'tenant_public_id' => $target->tenant->public_id,
                'billing_role' => 'extras',
                'type' => 'extras',
            ],
        ];

        if ($updates !== []) {
            $params['items'] = $updates;
        }

        /*
         * Se actualiza siempre el metadata aunque Business y
         * Restaurant usen exactamente el mismo Price de extras.
         */
        $remote = $this->stripe->subscriptions->update(
            $subscriptionId,
            $params,
            $this->options($op, 'extras-transition')
        );

        $this->sync->sync($target->tenant, $remote);

        return [
            'status' => $change->refresh()->status === 'completed'
                ? 'confirmed'
                : 'processing',
            'contract_id' => $target->public_id,
        ];
    }
}
