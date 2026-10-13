<?php

namespace App\Services\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Models\AddonPrice;
use App\Models\BillingOutbox;
use App\Models\PlanPrice;
use App\Models\PlanSubscription;
use App\Models\SubscriptionAddon;
use App\Models\SubscriptionChange;
use App\Models\Tenant;
use App\Models\UsagePeriod;
use App\Services\Fiscal\OveragePolicyService;
use DomainException;
use Illuminate\Support\Facades\DB;

final class ContractService
{
    public function __construct(
        private CatalogService $catalog,
        private EntitlementService $entitlements,
        private CatalogAccessService $catalogAccess,
        private OveragePolicyService $overagePolicies,
    ) {}

    public function create(
        Tenant $tenant,
        PlanPrice $price,
        bool $activate = false,
    ): PlanSubscription {
        return DB::transaction(function () use ($tenant, $price, $activate) {
            Tenant::query()
                ->whereKey($tenant->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($activate && $price->amount > 0) {
                throw new DomainException(
                    'El pago debe confirmarse antes de activar el contrato.'
                );
            }

            $subscription = PlanSubscription::query()->create([
                'plan_id' => $price->version->plan_id,
                'plan_version_id' => $price->plan_version_id,
                'plan_price_id' => $price->id,
                'status' => SubscriptionStatus::Pending,
                'provider' => $price->amount > 0 ? 'stripe' : 'local',
                'pending_tenant_id' => $price->amount > 0
                    ? $tenant->id
                    : null,
                'monthly_price_snapshot' => $this->catalog->decimal(
                    $price->amount
                ),
                'entitlements_snapshot' => $this->entitlements->forVersion(
                    $price->version
                ),
                'starts_at' => now(),
                'usage_anchor_at' => now(),
                'current_period_starts_at' => now(),
                'current_period_ends_at' => $price->interval === 'year'
                    ? now()->addYearNoOverflow()
                    : now()->addMonthNoOverflow(),
            ]);

            foreach ($price->version->availableAddons as $addonVersion) {
                if (! $addonVersion->pivot->automatic) {
                    continue;
                }

                $addonPrice = $addonVersion->prices
                    ->firstWhere('interval', 'month');

                if (! $addonPrice) {
                    throw new DomainException(
                        'El complemento automático no tiene precio mensual.'
                    );
                }

                $subscription->addons()->create([
                    'addon_version_id' => $addonVersion->id,
                    'addon_price_id' => $addonPrice->id,
                    'quantity' => 1,
                    'status' => 'pending',
                ]);
            }

            if ($activate) {
                $this->activate($subscription);
            }

            return $subscription->refresh();
        });
    }

    public function activate(PlanSubscription $subscription): void
    {
        DB::transaction(function () use ($subscription) {
            Tenant::query()
                ->whereKey($subscription->tenant_id)
                ->lockForUpdate()
                ->firstOrFail();

            $subscription->refresh();

            if ($subscription->ended_at) {
                return;
            }

            if ($subscription->price->amount > 0) {
                $links = $subscription->billingLinks()->get();

                if (
                    $links->count() !== 2
                    || $links->contains(
                        fn ($link) => $link->status !== 'active'
                    )
                ) {
                    return;
                }
            }

            $old = $this->entitlements->current($subscription->tenant);

            if (
                $old
                && $old->id !== $subscription->id
                && $old->price->amount > 0
            ) {
                $authorized = SubscriptionChange::query()
                    ->where('plan_subscription_id', $old->id)
                    ->where('kind', 'plan')
                    ->whereIn('status', ['pending', 'processing'])
                    ->where('effective_at', '<=', now())
                    ->where(function ($query) use ($subscription) {
                        $query
                            ->where(
                                'target_plan_subscription_id',
                                $subscription->id
                            )
                            ->orWhere(
                                'target_plan_price_id',
                                $subscription->plan_price_id
                            );
                    })
                    ->first();

                if (! $authorized) {
                    throw new DomainException(
                        'No se puede reemplazar un contrato pagado sin un cambio confirmado.'
                    );
                }
            }

            if ($old && $old->id !== $subscription->id) {
                if (
                    isset($authorized)
                    && $authorized->change_mode === 'immediate'
                ) {
                    $this->carryCurrentUsage($old, $subscription);
                }

                $this->overagePolicies->closeForContract($old->id);
                $old->update([
                    'current_tenant_id' => null,
                    'status' => SubscriptionStatus::Cancelled,
                    'ended_at' => now(),
                ]);

                $old->addons()
                    ->where('status', 'active')
                    ->update([
                        'status' => 'ended',
                        'ends_at' => now(),
                    ]);
            }

            $subscription->update([
                'current_tenant_id' => $subscription->tenant_id,
                'pending_tenant_id' => null,
                'status' => SubscriptionStatus::Active,
                'grace_ends_at' => null,
            ]);

            $subscription->addons()
                ->where('status', 'pending')
                ->whereHas(
                    'version',
                    fn ($query) => $query->where('mode', 'metered')
                )
                ->update([
                    'status' => 'active',
                    'starts_at' => now(),
                ]);

            $this->catalogAccess->syncTenant(
                $subscription->tenant
            );
        });
    }

    public function expireUnactivated(
        PlanSubscription $subscription
    ): void {
        DB::transaction(function () use ($subscription): void {
            Tenant::query()
                ->whereKey($subscription->tenant_id)
                ->lockForUpdate()
                ->firstOrFail();

            $subscription->refresh();

            if ($subscription->current_tenant_id || $subscription->ended_at) {
                return;
            }

            $subscription->update([
                'pending_tenant_id' => null,
                'status' => SubscriptionStatus::Cancelled,
                'cancelled_at' => now(),
                'ended_at' => now(),
            ]);

            $subscription->addons()
                ->whereIn('status', ['pending', 'active'])
                ->update([
                    'status' => 'ended',
                    'ends_at' => now(),
                ]);
        });
    }

    private function carryCurrentUsage(
        PlanSubscription $source,
        PlanSubscription $target,
    ): void {
        $targetBenefits = $this->entitlements->resolve($target);

        foreach (
            UsagePeriod::query()
                ->where('plan_subscription_id', $source->id)
                ->where('starts_at', '<=', now())
                ->where('ends_at', '>', now())
                ->lockForUpdate()
                ->with('feature')
                ->get() as $period
        ) {
            $period->update([
                'plan_subscription_id' => $target->id,
                'included' => $targetBenefits[$period->feature->code]
                    ?? $period->included,
            ]);
        }
    }

    public function enqueue(
        Tenant $tenant,
        string $kind,
        string $key,
        array $payload,
    ): BillingOutbox {
        $operation = BillingOutbox::query()->firstOrCreate(
            [
                'operation_key' => $tenant->id.':'.$key,
            ],
            [
                'kind' => $kind,
                'payload' => $payload,
                'available_at' => now(),
            ]
        );

        if (
            $operation->kind !== $kind
            || $operation->payload !== $payload
        ) {
            throw new DomainException(
                'La clave de idempotencia ya se usó para otra operación.'
            );
        }

        return $operation;
    }

    public function planChangeMode(
        PlanSubscription $current,
        PlanPrice $target,
    ): string {
        /*
         * Paid -> Free siempre espera al final del periodo.
         */
        if ($target->amount === 0) {
            return 'period_end';
        }

        /*
         * Cambio month <-> year nunca se hace inmediatamente.
         */
        if ($current->price->interval !== $target->interval) {
            return 'period_end';
        }

        $currentTier = (int) $current->version->plan->tier;
        $targetTier = (int) $target->version->plan->tier;

        /*
         * Sólo subir de tier es upgrade.
         * Igual tier o menor tier espera renovación.
         */
        return $targetTier > $currentTier
            ? 'immediate'
            : 'period_end';
    }

    public function requestPlanChange(
        PlanSubscription $subscription,
        PlanPrice $target,
        array $branches = [],
        array $memberships = [],
        ?string $operationId = null,
    ): SubscriptionChange {
        $mode = $this->planChangeMode($subscription, $target);

        return $this->createPlanChange(
            subscription: $subscription,
            target: $target,
            branches: $branches,
            memberships: $memberships,
            mode: $mode,
            operationId: $operationId,
        );
    }

    public function schedule(
        PlanSubscription $subscription,
        PlanPrice $target,
        array $branches = [],
    ): SubscriptionChange {
        return $this->createPlanChange(
            subscription: $subscription,
            target: $target,
            branches: $branches,
            memberships: [],
            mode: 'period_end',
        );
    }

    private function createPlanChange(
        PlanSubscription $subscription,
        PlanPrice $target,
        array $branches,
        array $memberships,
        string $mode,
        ?string $operationId = null,
    ): SubscriptionChange {
        return DB::transaction(function () use (
            $subscription,
            $target,
            $branches,
            $memberships,
            $mode,
            $operationId
        ) {
            Tenant::query()
                ->whereKey($subscription->tenant_id)
                ->lockForUpdate()
                ->firstOrFail();

            $subscription->refresh();

            if (
                $subscription->current_tenant_id === null
                || $subscription->cancel_at_period_end
                || $subscription->status !== SubscriptionStatus::Active
            ) {
                throw new DomainException(
                    'El contrato no permite cambios en este momento.'
                );
            }

            if ($subscription->plan_price_id === $target->id) {
                throw new DomainException('Ya tienes esta oferta.');
            }

            $this->assertNoPendingPurchase($subscription);
            $this->catalog->assertPurchasable($target);

            /*
             * Idempotencia para cambios de plan.
             */
            if ($operationId) {
                $operationKey = $subscription->tenant_id
                    .':plan-change:'
                    .$operationId;

                $existingOperation = BillingOutbox::query()
                    ->where('operation_key', $operationKey)
                    ->first();

                if ($existingOperation) {
                    $existingChange = SubscriptionChange::query()
                        ->findOrFail(
                            $existingOperation->payload['change_id']
                        );

                    if (
                        $existingChange->target_plan_price_id
                        !== $target->id
                    ) {
                        throw new DomainException(
                            'La clave de idempotencia ya corresponde a otro cambio.'
                        );
                    }

                    return $existingChange;
                }
            }

            if (
                SubscriptionChange::query()
                    ->where('plan_subscription_id', $subscription->id)
                    ->whereIn('status', ['pending', 'processing'])
                    ->exists()
            ) {
                throw new DomainException(
                    'Ya hay un cambio pendiente. Cancélalo antes de solicitar otro.'
                );
            }

            $benefits = $target->plan_version_id
                === $subscription->plan_version_id
                ? $this->entitlements->resolve($subscription)
                : $this->entitlements->forVersion($target->version);

            $limit = $benefits['included_branches'] ?? 0;

            $this->validateBranches(
                $subscription->tenant,
                $limit,
                $branches
            );
            $this->validateMemberships(
                $subscription->tenant,
                $benefits['max_users'] ?? 0,
                $memberships,
            );

            if (! in_array($mode, ['immediate', 'period_end'], true)) {
                throw new DomainException('Modo de cambio inválido.');
            }

            $effectiveAt = $mode === 'immediate'
                ? now()
                : $subscription->current_period_ends_at;

            if (! $effectiveAt) {
                throw new DomainException(
                    'No se pudo determinar la fecha efectiva del cambio.'
                );
            }

            $change = SubscriptionChange::query()->create([
                'plan_subscription_id' => $subscription->id,
                'kind' => 'plan',
                'change_mode' => $mode,
                'status' => $mode === 'immediate'
                    ? 'processing'
                    : 'pending',
                'target_plan_price_id' => $target->id,
                'retained_branch_ids' => $branches,
                'retained_membership_ids' => $memberships,
                'effective_at' => $effectiveAt,
            ]);

            $kind = $mode === 'immediate'
                ? 'upgrade_plan'
                : 'schedule_plan';

            $key = $operationId
                ? 'plan-change:'.$operationId
                : ($mode === 'immediate'
                    ? 'upgrade:'.$change->public_id
                    : 'schedule:'.$change->public_id);

            $this->enqueue(
                $subscription->tenant,
                $kind,
                $key,
                [
                    'change_id' => $change->id,
                ]
            );

            return $change;
        });
    }

    public function validateBranches(
        Tenant $tenant,
        ?int $limit,
        array $retained,
    ): void {
        if ($limit === null) {
            return;
        }

        $ids = $tenant->branches()
            ->where('is_active', true)
            ->where('billing_enabled', true)
            ->pluck('public_id')
            ->all();

        if (
            count($ids) > $limit
            && (
                count($retained) !== $limit
                || count(array_unique($retained)) !== count($retained)
                || array_diff($retained, $ids)
            )
        ) {
            throw new DomainException(
                'Selecciona exactamente las sucursales que seguirán operativas con el nuevo cupo.'
            );
        }
    }

    public function validateMemberships(Tenant $tenant, ?int $limit, array $retained): void
    {
        if ($limit === null) {
            return;
        }

        $active = $tenant->memberships()->where('status', \App\Enums\MembershipStatus::Active->value)->get();
        if ($active->count() <= $limit) {
            return;
        }

        $ids = $active->pluck('public_id')->all();
        if (count($retained) !== $limit
            || count(array_unique($retained)) !== count($retained)
            || array_diff($retained, $ids)) {
            throw new DomainException('Selecciona exactamente los usuarios que seguirán activos con el nuevo cupo.');
        }

        $previous = getPermissionsTeamId();
        try {
            setPermissionsTeamId($tenant->id);
            $ownerIds = $active->filter(function ($membership): bool {
                $membership->user->unsetRelation('roles');
                return $membership->user->hasRole(\App\Enums\RoleName::Owner->value);
            })->pluck('public_id')->all();
        } finally {
            setPermissionsTeamId($previous);
        }

        if (array_diff($ownerIds, $retained)) {
            throw new DomainException('La selección debe conservar a todos los propietarios activos.');
        }
    }

    public function add(
        PlanSubscription $subscription,
        AddonPrice $price,
        int $quantity,
        string $key,
    ): BillingOutbox {
        return DB::transaction(function () use (
            $subscription,
            $price,
            $quantity,
            $key
        ) {
            Tenant::query()
                ->whereKey($subscription->tenant_id)
                ->lockForUpdate()
                ->firstOrFail();

            $subscription->refresh();

            if (
                $quantity < 1
                || $quantity > 100
                || $subscription->status !== SubscriptionStatus::Active
                || ! $subscription->current_tenant_id
                || $subscription->cancel_at_period_end
            ) {
                throw new DomainException('Cantidad o contrato inválido.');
            }

            if (
                ! $subscription->version
                    ->availableAddons()
                    ->where(
                        'addon_versions.id',
                        $price->addon_version_id
                    )
                    ->exists()
                || $price->version->mode !== 'recurring'
            ) {
                throw new DomainException(
                    'El complemento no está disponible en tu contrato.'
                );
            }

            $prior = BillingOutbox::query()
                ->where(
                    'operation_key',
                    $subscription->tenant_id.':addon:'.$key
                )
                ->first();

            if ($prior) {
                return $this->enqueue(
                    $subscription->tenant,
                    'add_addon',
                    'addon:'.$key,
                    [
                        'contract_id' => $subscription->id,
                        'addon_price_id' => $price->id,
                        'quantity' => $quantity,
                    ]
                );
            }

            $this->assertNoPendingPurchase($subscription);
            $this->catalog->mapping($price);

            $existing = $subscription->addons()
                ->where('addon_price_id', $price->id)
                ->where('status', 'active')
                ->first();

            if ($existing && $quantity <= $existing->quantity) {
                throw new DomainException(
                    'Usa la reducción programada para disminuir la cantidad total.'
                );
            }

            if (
                SubscriptionChange::query()
                    ->where('plan_subscription_id', $subscription->id)
                    ->whereIn('status', ['pending', 'processing'])
                    ->exists()
            ) {
                throw new DomainException(
                    'Resuelve el cambio pendiente antes de añadir complementos.'
                );
            }

            return $this->enqueue(
                $subscription->tenant,
                'add_addon',
                'addon:'.$key,
                [
                    'contract_id' => $subscription->id,
                    'addon_price_id' => $price->id,
                    'quantity' => $quantity,
                ]
            );
        });
    }

    public function reduce(
        PlanSubscription $subscription,
        SubscriptionAddon $addon,
        int $quantity,
        array $branches,
    ): SubscriptionChange {
        return DB::transaction(function () use (
            $subscription,
            $addon,
            $quantity,
            $branches
        ) {
            Tenant::query()
                ->whereKey($subscription->tenant_id)
                ->lockForUpdate()
                ->firstOrFail();

            $addon->refresh();

            $this->assertNoPendingPurchase($subscription);

            if (
                $addon->plan_subscription_id !== $subscription->id
                || $addon->status !== 'active'
                || $addon->version->mode !== 'recurring'
                || $quantity < 0
                || $quantity >= $addon->quantity
            ) {
                throw new DomainException('Reducción inválida.');
            }

            if (
                SubscriptionChange::query()
                    ->where('plan_subscription_id', $subscription->id)
                    ->whereIn('status', ['pending', 'processing'])
                    ->exists()
            ) {
                throw new DomainException(
                    'Ya hay un cambio pendiente.'
                );
            }

            $limit = $this->entitlements
                ->resolve($subscription)['included_branches'];

            $this->validateBranches(
                $subscription->tenant,
                $limit === null
                    ? null
                    : $limit - $addon->quantity + $quantity,
                $branches
            );

            $ends = $subscription->billingLinks()
                ->where('role', 'extras')
                ->firstOrFail()
                ->period_ends_at;

            $change = SubscriptionChange::query()->create([
                'plan_subscription_id' => $subscription->id,
                'kind' => 'addon',
                'change_mode' => 'period_end',
                'subscription_addon_id' => $addon->id,
                'quantity' => $quantity,
                'retained_branch_ids' => $branches,
                'effective_at' => $ends,
            ]);

            $this->enqueue(
                $subscription->tenant,
                'schedule_addon',
                'reduce:'.$change->public_id,
                [
                    'change_id' => $change->id,
                ]
            );

            return $change;
        });
    }

    public function assertNoPendingPurchase(
        PlanSubscription $subscription
    ): void {
        if (
            BillingOutbox::query()
                ->where('kind', 'add_addon')
                ->where('payload->contract_id', $subscription->id)
                ->where(function ($query) {
                    $query
                        ->whereIn('status', [
                            'pending',
                            'processing',
                            'reconciliation_required',
                        ])
                        ->orWhere(
                            'result->status',
                            'awaiting_payment'
                        );
                })
                ->exists()
        ) {
            throw new DomainException(
                'Resuelve el pago pendiente del complemento antes de cambiar el contrato.'
            );
        }
    }

    public function assertNoPendingChange(
        PlanSubscription $subscription,
        string $action,
    ): void {
        $change = SubscriptionChange::query()
            ->where(
                'plan_subscription_id',
                $subscription->id,
            )
            ->whereIn('status', ['pending', 'processing'])
            ->orderByRaw(
                "case when status = 'processing' then 0 else 1 end"
            )
            ->oldest('id')
            ->first();

        if (! $change) {
            return;
        }

        $operation = match ($action) {
            'cancel' => 'cancelar la suscripción',
            'reactivate' => 'reactivar la suscripción',
            default => throw new DomainException(
                'La operación contractual es inválida.'
            ),
        };

        if (
            $change->status === 'processing'
            || $change->change_mode === 'immediate'
        ) {
            throw new DomainException(
                'Hay un cambio de suscripción en proceso. Actualiza su estado antes de '.$operation.'.'
            );
        }

        throw new DomainException(
            'Cancela el cambio programado antes de '.$operation.'.'
        );
    }

    public function cancelChange(
        SubscriptionChange $change
    ): BillingOutbox {
        $change->refresh();

        /*
         * Un upgrade inmediato puede estar esperando pago en Stripe.
         * No lo tratamos como Subscription Schedule porque no lo es.
         */
        if ($change->change_mode === 'immediate') {
            throw new DomainException(
                'Un upgrade inmediato en proceso no puede cancelarse como cambio programado.'
            );
        }

        if (
            $change->status !== 'pending'
            || now()->gte($change->effective_at)
        ) {
            throw new DomainException(
                'El cambio ya está en ejecución.'
            );
        }

        return $this->enqueue(
            $change->tenant,
            'cancel_change',
            'cancel-change:'.$change->public_id,
            [
                'change_id' => $change->id,
            ]
        );
    }
}
