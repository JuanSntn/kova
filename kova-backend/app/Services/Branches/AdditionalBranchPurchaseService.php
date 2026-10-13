<?php

namespace App\Services\Branches;

use App\Models\AddonPrice;
use App\Models\BillingOutbox;
use App\Models\PlanSubscription;
use App\Models\SubscriptionAddon;
use App\Models\Tenant;
use App\Services\Subscriptions\BillingProcessor;
use App\Services\Subscriptions\CapacityService;
use App\Services\Subscriptions\CatalogService;
use App\Services\Subscriptions\ContractService;
use App\Services\Subscriptions\EntitlementService;
use DomainException;

/**
 * Compra el único complemento que puede ampliar el cupo de sucursales.
 *
 * El navegador nunca elige una oferta ni una cantidad: ambas se derivan del
 * contrato actual. Esto evita que un cliente altere el precio o compre un
 * complemento perteneciente a otro plan.
 */
final class AdditionalBranchPurchaseService
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly CapacityService $capacity,
        private readonly CatalogService $catalog,
        private readonly ContractService $contracts,
        private readonly BillingProcessor $processor,
    ) {}

    /**
     * @return array{available: bool, can_purchase: bool, price: array{amount: int, currency: string, interval: string}|null, pending: array{status: string, invoice_url: string|null}|null, active: array{quantity: int, amount: int, currency: string, interval: string, next_billing_at: string|null, required_retained_branches: int, effective_at: string|null}|null, can_cancel: bool}
     */
    public function summary(Tenant $tenant, bool $canManageBilling): array
    {
        $subscription = $this->entitlements->current($tenant);
        $price = $subscription
            ? $this->priceFor($subscription)
            : null;
        $active = $subscription
            ? $this->activeAddon($subscription)
            : null;

        if (! $subscription) {
            return [
                'available' => false,
                'can_purchase' => false,
                'price' => null,
                'pending' => null,
                'active' => null,
                'can_cancel' => false,
            ];
        }

        $used = $tenant->branches()
            ->where('billing_enabled', true)
            ->count();
        $usage = $this->capacity->usage(
            $tenant,
            'included_branches',
            $used,
        );

        $pending = $price
            ? $this->pendingPurchase($subscription, $price)
            : null;
        $pendingReduction = $this->pendingReduction($subscription, $active);

        $targetLimit = null;
        if ($active) {
            $limit = $this->entitlements->resolve($subscription)['included_branches'];
            $targetLimit = $limit === null
                ? null
                : max(0, $limit - 1);
        }

        $nextBillingAt = $subscription->billingLinks()
            ->where('role', 'extras')
            ->first()
            ?->period_ends_at;

        return [
            'available' => $price !== null,
            'can_purchase' => $canManageBilling
                && $price !== null
                && $usage['reason'] === 'limit_reached'
                && $pending === null,
            'price' => $price
                ? [
                    'amount' => $price->amount,
                    'currency' => $price->currency,
                    'interval' => $price->interval,
                ]
                : null,
            'pending' => $pending,
            'active' => $active
                ? [
                    'quantity' => $active->quantity,
                    'amount' => $active->price->amount,
                    'currency' => $active->price->currency,
                    'interval' => $active->price->interval,
                    'next_billing_at' => $nextBillingAt?->toIso8601String(),
                    'required_retained_branches' => $targetLimit === null
                        ? 0
                        : ($used > $targetLimit ? $targetLimit : 0),
                    'effective_at' => $pendingReduction?->effective_at?->toIso8601String(),
                ]
                : null,
            'can_cancel' => $canManageBilling
                && $active !== null
                && $pendingReduction === null,
        ];
    }

    /**
     * @return array{status: string, invoice_url: string|null}
     */
    public function purchase(Tenant $tenant, string $operationId): array
    {
        $subscription = $this->entitlements->current($tenant);

        if (! $subscription) {
            throw new DomainException(
                'No hay un contrato activo para contratar otra sucursal.',
            );
        }

        $price = $this->priceFor($subscription);

        if (! $price) {
            throw new DomainException(
                'Tu plan actual no permite contratar sucursales adicionales.',
            );
        }

        $used = $tenant->branches()
            ->where('billing_enabled', true)
            ->count();
        $usage = $this->capacity->usage(
            $tenant,
            'included_branches',
            $used,
        );

        if ($usage['can_add']) {
            throw new DomainException(
                'Ya tienes un cupo disponible. Agrega la sucursal sin contratar otro complemento.',
            );
        }

        if ($usage['reason'] !== 'limit_reached') {
            throw new DomainException(
                'El contrato no permite ampliar sucursales en este momento.',
            );
        }

        $existing = $subscription->addons()
            ->where('addon_price_id', $price->id)
            ->where('status', 'active')
            ->first();

        $operation = $this->contracts->add(
            $subscription,
            $price,
            ($existing?->quantity ?? 0) + 1,
            $operationId,
        );

        $result = $this->processor->run($operation);

        return [
            'status' => (string) ($result['status'] ?? 'processing'),
            'invoice_url' => isset($result['invoice_url'])
                ? (string) $result['invoice_url']
                : null,
        ];
    }

    /**
     * Programa la baja de una sucursal adicional al final del periodo actual.
     * La cantidad y el complemento se derivan del contrato; el navegador sólo
     * puede indicar qué sucursales conservar si el nuevo cupo ya no las cubre.
     *
     * @param array<int, string> $retainedBranchIds
     * @return array{status: string, effective_at: string}
     */
    public function cancel(Tenant $tenant, array $retainedBranchIds): array
    {
        $subscription = $this->entitlements->current($tenant);
        $addon = $subscription
            ? $this->activeAddon($subscription)
            : null;

        if (! $subscription || ! $addon) {
            throw new DomainException(
                'No hay una sucursal adicional activa para cancelar.',
            );
        }

        if ($this->pendingReduction($subscription, $addon)) {
            throw new DomainException(
                'Ya hay una cancelación de sucursal adicional programada.',
            );
        }

        $change = $this->contracts->reduce(
            $subscription,
            $addon,
            $addon->quantity - 1,
            $retainedBranchIds,
        );

        $operation = BillingOutbox::query()
            ->where(
                'operation_key',
                $subscription->tenant_id.':reduce:'.$change->public_id,
            )
            ->firstOrFail();

        $this->processor->run($operation);

        return [
            'status' => $change->status,
            'effective_at' => $change->effective_at->toIso8601String(),
        ];
    }

    private function priceFor(PlanSubscription $subscription): ?AddonPrice
    {
        $version = $subscription->version;

        if (! $version) {
            return null;
        }

        $addon = $version->availableAddons()
            ->where('mode', 'recurring')
            ->whereHas(
                'addon',
                fn ($query) => $query->where('code', 'extra_branch'),
            )
            ->first();

        $price = $addon?->prices()
            ->where('interval', 'month')
            ->first();

        if (! $price) {
            return null;
        }

        try {
            $this->catalog->mapping($price);
        } catch (DomainException) {
            return null;
        }

        return $price;
    }

    private function activeAddon(
        PlanSubscription $subscription,
    ): ?SubscriptionAddon {
        return $subscription->addons()
            ->where('status', 'active')
            ->whereHas(
                'version.addon',
                fn ($query) => $query->where('code', 'extra_branch'),
            )
            ->orderByDesc('id')
            ->first();
    }

    private function pendingReduction(
        PlanSubscription $subscription,
        ?SubscriptionAddon $addon,
    ): ?\App\Models\SubscriptionChange {
        if (! $addon) {
            return null;
        }

        return \App\Models\SubscriptionChange::query()
            ->where('plan_subscription_id', $subscription->id)
            ->where('subscription_addon_id', $addon->id)
            ->whereIn('status', ['pending', 'processing'])
            ->latest('id')
            ->first();
    }

    /**
     * @return array{status: string, invoice_url: string|null}|null
     */
    private function pendingPurchase(
        PlanSubscription $subscription,
        AddonPrice $price,
    ): ?array {
        $operation = BillingOutbox::query()
            ->where('kind', 'add_addon')
            ->where('payload->contract_id', $subscription->id)
            ->latest('id')
            ->get()
            ->first(function (BillingOutbox $candidate) use ($price): bool {
                return (int) ($candidate->payload['addon_price_id'] ?? 0)
                    === $price->id
                    && (
                        in_array($candidate->status, [
                            'pending',
                            'processing',
                            'reconciliation_required',
                        ], true)
                        || ($candidate->result['status'] ?? null)
                            === 'awaiting_payment'
                    );
            });

        if (! $operation) {
            return null;
        }

        return [
            'status' => (string) (
                $operation->result['status']
                ?? $operation->status
            ),
            'invoice_url' => isset($operation->result['invoice_url'])
                ? (string) $operation->result['invoice_url']
                : null,
        ];
    }
}
