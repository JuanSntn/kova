<?php

namespace App\Services\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Models\AddonPrice;
use App\Models\BillingOutbox;
use App\Models\BillingPriceMapping;
use App\Models\BillingSubscriptionLink;
use App\Models\PlanPrice;
use App\Models\PlanSubscription;
use App\Models\SubscriptionChange;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Stripe\Subscription;
use App\Enums\MembershipStatus;
use App\Services\Auth\SessionInvalidator;

final class StripeContractSynchronizer
{
    public function __construct(
        private ContractService $contracts,
        private EntitlementService $entitlements,
        private CatalogService $catalog,
        private BillingAccessPolicy $billingAccess,
        private SessionInvalidator $sessions,
    ) {}

    public function sync(Tenant $tenant, Subscription $remote): PlanSubscription
    {
        if ((is_string($remote->customer) ? $remote->customer : $remote->customer->id) !== $tenant->stripe_id) {
            throw new DomainException('El cliente de cobro no pertenece al negocio.');
        }

        return DB::transaction(function () use ($tenant, $remote) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $transitioned = false;
            $link = BillingSubscriptionLink::where('stripe_subscription_id', $remote->id)->first();
            $s = $link ? PlanSubscription::findOrFail($link->plan_subscription_id) : PlanSubscription::where('public_id', data_get($remote, 'metadata.contract_public_id'))->first();
            if (! $s) {
                throw new DomainException('Suscripción de Stripe sin contrato comercial reconocido.');
            }
            if ($s->ended_at) {
                return $s;
            }
            $role = $link?->role ?? data_get($remote, 'metadata.billing_role');
            if (! in_array($role, ['base', 'extras'])) {
                throw new DomainException('Rol de cobro desconocido.');
            }
            $items = $remote->items->data ?? [];
            $start = data_get($remote, 'current_period_start') ?? data_get($items, '0.current_period_start');
            $end = data_get($remote, 'current_period_end') ?? data_get($items, '0.current_period_end');
            if (! $start || ! $end || $start >= $end) {
                throw new DomainException('Período de cobro inválido.');
            }
            $start = CarbonImmutable::createFromTimestampUTC($start);
            $end = CarbonImmutable::createFromTimestampUTC($end);
            if ($role === 'base' && $remote->status !== 'canceled') {
                $priceId = data_get($items, '0.price.id');
                $mapping = BillingPriceMapping::query()
                    ->where(
                        'stripe_price_id',
                        $priceId
                    )
                    ->whereNotNull(
                        'plan_price_id'
                    )
                    ->where(
                        'livemode',
                        config(
                            'billing.livemode',
                            false
                        )
                    )
                    ->first();

                if (! $mapping) {
                    throw new DomainException(
                        'El Price de Stripe '
                            .$priceId
                            .' no está mapeado a una oferta de plan de Kova para '
                            .(
                                config(
                                    'billing.livemode',
                                    false
                                )
                                ? 'live mode'
                                : 'test mode'
                            )
                            .'.'
                    );
                }
                if ($s->plan_price_id !== $mapping->plan_price_id) {
                    $source = $s;

                    $change = SubscriptionChange::query()
                        ->where('plan_subscription_id', $source->id)
                        ->where('kind', 'plan')
                        ->where('target_plan_price_id', $mapping->plan_price_id)
                        ->whereIn('status', ['pending', 'processing'])
                        ->where('effective_at', '<=', now())
                        ->first();

                    if (! $change) {
                        throw new DomainException('Cambio remoto no autorizado: requiere reconciliación.');
                    }

                    $next = $change->targetContract;

                    if (! $next) {
                        $targetPrice = PlanPrice::query()->findOrFail($mapping->plan_price_id);
                        $next = $this->contracts->create($tenant, $targetPrice);

                        $effectiveAt = CarbonImmutable::parse($change->effective_at);
                        $usageAnchor = $change->change_mode === 'immediate'
                            ? ($source->usage_anchor_at ?? $source->current_period_starts_at ?? $effectiveAt)
                            : $effectiveAt;

                        $next->update([
                            'starts_at' => $effectiveAt,
                            'usage_anchor_at' => $usageAnchor,
                        ]);

                        $change->update([
                            'target_plan_subscription_id' => $next->id,
                            'status' => 'processing',
                        ]);
                    }

                    // Sólo movemos BASE. Extras permanece ligado al contrato viejo hasta que Stripe confirme su transición.
                    if ($link) {
                        $link->update([
                            'plan_subscription_id' => $next->id,
                            'status' => 'pending',
                        ]);
                    }

                    // Guardamos el extras original para completar la segunda mitad.
                    $extrasLink = $source->billingLinks()->where('role', 'extras')->first();

                    if ($extrasLink) {
                        $this->contracts->enqueue($tenant, 'transition_extras', 'transition-extras:'.$change->public_id, [
                            'change_id' => $change->id,
                            'stripe_subscription_id' => $extrasLink->stripe_subscription_id,
                        ]);
                    }

                    $s = $next;
                    $transitioned = true;

                    PlanSubscription::query()
                        ->where('stripe_subscription_id', $remote->id)
                        ->where('id', '!=', $s->id)
                        ->update(['stripe_subscription_id' => null]);
                }
                $s->update(['stripe_subscription_id' => $remote->id, 'stripe_price_id' => $priceId, 'current_period_starts_at' => $start, 'current_period_ends_at' => $end, 'cancel_at_period_end' => (bool) $remote->cancel_at_period_end]);
                if (! $link && ! $transitioned && $s->status === SubscriptionStatus::Pending && ! $s->current_tenant_id) {
                    $s->update(['starts_at' => CarbonImmutable::createFromTimestampUTC($remote->start_date), 'usage_anchor_at' => CarbonImmutable::createFromTimestampUTC($remote->billing_cycle_anchor ?? $remote->start_date)]);
                }
            }
            $status = $remote->status;
            $invoiceStatus = data_get($remote, 'latest_invoice.status');
            if ($role === 'base' && $status === 'active' && $s->status === SubscriptionStatus::Pending && $invoiceStatus !== 'paid') {
                $status = 'pending';
            }
            $link = BillingSubscriptionLink::updateOrCreate(['stripe_subscription_id' => $remote->id], [
                'plan_subscription_id' => $s->id,
                'role' => $role,
                'status' => $status,
                'period_starts_at' => $start,
                'period_ends_at' => $end,
                'subscription_id' => $tenant->subscriptions()->where('stripe_id', $remote->id)->value('id'),
                'last_event_at' => now(),
            ]);

            if (
                $role === 'base'
                && $status === 'incomplete_expired'
                && $s->current_tenant_id === null
            ) {
                $this->contracts->expireUnactivated($s);

                return $s->refresh();
            }

            $unpaid = data_get($remote, 'kova_unpaid_since');
            if ($unpaid || in_array($status, ['past_due', 'unpaid', 'incomplete_expired'])) {
                $since = $unpaid ? CarbonImmutable::createFromTimestampUTC($unpaid) : ($link->past_due_since ?? now());
                $link->update(['past_due_since' => $since]);
            } elseif ($status === 'active' && data_get($remote, 'kova_debt_checked') === true) {
                $link->update(['past_due_since' => null]);
            }

            if ($role === 'extras' && ! $remote->pending_update) {
                $change = SubscriptionChange::query()
                    ->where('plan_subscription_id', $s->id)
                    ->where('kind', 'plan')
                    ->whereIn('status', ['pending', 'processing'])
                    ->where('effective_at', '<=', now())
                    ->first();

                $target = $change?->targetContract;
                $metadataContract = data_get(
                    $remote,
                    'metadata.contract_public_id'
                );
                $baseConfirmed = $target?->billingLinks()
                    ->where('role', 'base')
                    ->where('status', 'active')
                    ->exists() ?? false;
                $transitionConfirmed = $target
                    && $baseConfirmed
                    && (
                        $metadataContract === $target->public_id
                        || $change->change_mode === 'period_end'
                    );

                /*
                 * Un webhook ordinario de EXTRAS del contrato origen no
                 * confirma un upgrade. Para cambios inmediatos exigimos el
                 * metadata que transitionExtras escribe después de que BASE
                 * quedó pagada. Para cambios programados BASE debe haber
                 * alcanzado primero el contrato destino.
                 */
                if ($transitionConfirmed) {
                    if ($link) {
                        $link->update([
                            'plan_subscription_id' => $target->id,
                            'status' => $status,
                        ]);
                    }
                    $s = $target;
                    $transitioned = true;
                }
            }

            if (
                $role === 'extras'
                && $status === 'active'
                && ! $remote->pending_update
            ) {
                $this->syncAddons(
                    $s,
                    $items
                );
            }
            if ($role === 'base' && $status === 'active' && ! $transitioned && ! $s->billingLinks()->where('role', 'extras')->exists()) {
                $this->contracts->enqueue($tenant, 'provision_extras', 'provision:'.$s->public_id, ['contract_id' => $s->id]);
            }
            if ($status === 'canceled') {
                if ($role === 'base') {
                    $this->finish($s);
                } elseif (! $s->cancel_at_period_end) {
                    $link->update(['past_due_since' => $link->past_due_since ?? now()]);
                }
            }
            $this->refreshAccess($s);

            return $s->refresh();
        });
    }

    private function syncAddons(PlanSubscription $s, array $items): void
    {
        $seen = [];
        BillingOutbox::where('kind', 'add_addon')->where('payload->contract_id', $s->id)->where('result->status', 'awaiting_payment')->get()->each(function ($op) {
            $result = $op->result;
            $result['status'] = 'settled';
            $op->update(['result' => $result]);
        });
        foreach ($items as $item) {
            $mapping = BillingPriceMapping::where('stripe_price_id', $item->price->id)->whereNotNull('addon_price_id')->where('livemode', config('billing.livemode', false))->firstOrFail();
            $price = AddonPrice::findOrFail($mapping->addon_price_id);
            // A previously contracted addon stays valid after the catalog is retired.
            $addon = $s->addons()->where('addon_price_id', $price->id)->whereIn('status', ['pending', 'active'])->first();
            if (! $addon && ! $s->version->availableAddons()->where('addon_versions.id', $price->addon_version_id)->exists()) {
                throw new DomainException('Complemento remoto no autorizado.');
            }
            $quantity = $price->version->mode === 'metered' ? 1 : $item->quantity;
            if ($addon && $addon->status === 'active' && $addon->quantity !== $quantity) {
                $addon->update(['status' => 'ended', 'ends_at' => now()]);
                $addon = null;
            }
            $addon ??= $s->addons()->make(['addon_price_id' => $price->id, 'addon_version_id' => $price->addon_version_id]);
            $addon->fill(['quantity' => $quantity, 'stripe_item_id' => $item->id, 'status' => 'active', 'starts_at' => $addon->starts_at ?? now(), 'ends_at' => null])->save();
            $seen[] = $addon->id;
        }
        $s->addons()->where('status', 'active')->whereNotIn('id', $seen)->update(['status' => 'ended', 'ends_at' => now()]);
        foreach (SubscriptionChange::where('plan_subscription_id', $s->id)->where('kind', 'addon')->where('status', 'pending')->where('effective_at', '<=', now())->get() as $change) {
            $addon = $s->addons()->find($change->subscription_addon_id);
            $currentAddon = $s->addons()->where('addon_price_id', $addon->addon_price_id)->where('status', 'active')->first();
            if (($change->quantity === 0 && $addon->status === 'ended') || ($currentAddon && $currentAddon->quantity === $change->quantity)) {
                $this->completeChange($change, $s);
            }
        }
    }

    public function refreshAccess(PlanSubscription $s): void
    {
        $s->refresh();
        if ($s->ended_at) {
            return;
        }
        $links = $s->billingLinks()->get();
        $due = $links->whereNotNull('past_due_since')->min('past_due_since');
        if ($due) {
            $grace = CarbonImmutable::parse($due)->addDays(7);
            $s->update([
                'grace_ends_at' => $grace,
                'status' => SubscriptionStatus::PastDue,
            ]);
            $current = $this->entitlements->current($s->tenant);
            if ($current && $current->id !== $s->id && $current->price->amount > 0) {
                $current->update([
                    'grace_ends_at' => $grace,
                    'status' => SubscriptionStatus::PastDue,
                ]);
            }

            $this->billingAccess->applyExpiredGrace($s->tenant);

            return;
        }
        $old = $this->entitlements->current($s->tenant);
        $this->contracts->activate($s);
        $s->refresh();
        if ($s->current_tenant_id && $old && $old->id !== $s->id) {
            foreach (
                SubscriptionChange::query()
                    ->where(
                        'plan_subscription_id',
                        $old->id
                    )
                    ->where(
                        'target_plan_price_id',
                        $s->plan_price_id
                    )
                    ->whereIn(
                        'status',
                        [
                            'pending',
                            'processing',
                        ]
                    )
                    ->get() as $change
            ) {
                $this->completeChange(
                    $change,
                    $s
                );
            }
        }
    }

    private function completeChange(SubscriptionChange $change, PlanSubscription $s): void
    {
        if ($change->retained_branch_ids) {
            $s->tenant->branches()->whereNotIn('public_id', $change->retained_branch_ids)->update(['billing_enabled' => false]);
        }
        if ($change->retained_membership_ids) {
            $s->tenant->memberships()
                ->where('status', MembershipStatus::Active->value)
                ->whereNotIn('public_id', $change->retained_membership_ids)
                ->with('user')
                ->get()
                ->each(function ($membership): void {
                    $membership->update(['status' => MembershipStatus::Suspended]);
                    $this->sessions->invalidate($membership->user);
                });
        }
        $change->update(['status' => 'completed', 'completed_at' => now()]);
    }

    private function finish(PlanSubscription $subscription): void
    {
        /*
         * Si BASE terminó, EXTRAS ya no debe seguir renovándose aunque
         * exista deuda y el contrato conserve acceso durante la gracia.
         */
        $this->contracts->enqueue(
            $subscription->tenant,
            'close_extras',
            'close:'.$subscription->public_id,
            [
                'contract_id' => $subscription->id,
            ]
        );

        if ($subscription->billingLinks()->whereNotNull('past_due_since')->exists()) {
            $subscription->update([
                'status' => SubscriptionStatus::PastDue,
                'grace_ends_at' => $subscription->grace_ends_at ?? now(),
            ]);
            $this->billingAccess->applyExpiredGrace(
                $subscription->tenant
            );

            return;
        }

        $subscription->update([
            'current_tenant_id' => null,
            'pending_tenant_id' => null,
            'ended_at' => now(),
            'cancelled_at' => now(),
            'cancel_at_period_end' => false,
            'status' => SubscriptionStatus::Cancelled,
        ]);

        if ($this->entitlements->current($subscription->tenant)) {
            return;
        }

        $change = SubscriptionChange::query()
            ->where('plan_subscription_id', $subscription->id)
            ->where('kind', 'plan')
            ->whereIn('status', ['pending', 'processing'])
            ->where('effective_at', '<=', now())
            ->first();

        $price = $change
            ? PlanPrice::query()->findOrFail($change->target_plan_price_id)
            : $this->catalog->price('free');

        if ($price->amount !== 0) {
            throw new DomainException(
                'Cancelación inesperada durante cambio pagado.'
            );
        }

        $free = $this->contracts->create(
            $subscription->tenant,
            $price,
            true
        );

        if ($change) {
            $change->update([
                'target_plan_subscription_id' => $free->id,
            ]);

            $this->completeChange($change, $free);

            return;
        }

        if ($subscription->cancellation_branch_ids) {
            $subscription->tenant
                ->branches()
                ->whereNotIn(
                    'public_id',
                    $subscription->cancellation_branch_ids
                )
                ->update([
                    'billing_enabled' => false,
                ]);
        }
        if ($subscription->cancellation_membership_ids) {
            $subscription->tenant->memberships()
                ->where('status', MembershipStatus::Active->value)
                ->whereNotIn('public_id', $subscription->cancellation_membership_ids)
                ->with('user')
                ->get()
                ->each(function ($membership): void {
                    $membership->update(['status' => MembershipStatus::Suspended]);
                    $this->sessions->invalidate($membership->user);
                });
        }
    }
}
