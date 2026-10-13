<?php

namespace App\Services\Memberships;

use App\Enums\SubscriptionStatus;
use App\Exceptions\BillingVerificationUnavailableException;
use App\Models\Tenant;
use App\Services\Subscriptions\BillingReconciler;
use App\Services\Subscriptions\EntitlementService;
use DomainException;

final class MembershipBillingGuard
{
    public function __construct(
        private EntitlementService $entitlements,
        private BillingReconciler $reconciler,
    ) {}

    public function assertCanExpand(Tenant $tenant): void
    {
        $contract = $this->entitlements->current($tenant);
        if (! $contract) {
            throw new DomainException('El negocio no tiene un contrato vigente.');
        }

        if ((int) $contract->price->amount > 0) {
            if (! $this->reconciler->run($tenant, true)) {
                throw new BillingVerificationUnavailableException('No pudimos confirmar la suscripción con Stripe. Intenta nuevamente en unos minutos.');
            }

            $contract = $this->entitlements->current($tenant)?->refresh();
            $links = $contract?->billingLinks()->whereIn('role', ['base', 'extras'])->get();
            $roles = $links?->pluck('role')->unique()->sort()->values()->all() ?? [];
            $confirmed = $contract
                && $contract->status === SubscriptionStatus::Active
                && $contract->ended_at === null
                && $roles === ['base', 'extras']
                && $links->every(fn ($link) => $link->status === 'active' && $link->past_due_since === null);

            if (! $confirmed) {
                throw new DomainException('La suscripción debe estar pagada y activa para ampliar usuarios.');
            }
        }

        $this->entitlements->assertWritable($tenant);
    }
}
