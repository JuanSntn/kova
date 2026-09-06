<?php

namespace App\Services\Billing;

use App\Contracts\Billing\StripeBillingGateway;
use App\Data\Billing\BillingSummaryData;
use App\Data\Billing\StripeBillingSnapshot;
use App\Data\Billing\StripeSubscriptionData;
use App\Enums\PermissionName;
use App\Enums\PlanCode;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

final class BillingSummaryService
{
    public function __construct(
        private readonly StripeBillingGateway $stripeBilling,
        private readonly BillingPlanResolver $planResolver,
    ) {}

    public function build(
        Tenant $tenant,
        ?User $user = null
    ): BillingSummaryData {

        $localActiveSubscription = $tenant
            ->planSubscriptions()
            ->with('plan')
            ->where(
                'status',
                SubscriptionStatus::Active->value
            )
            ->latest('starts_at')
            ->first();





        $localLastStripeSubscription = $tenant
            ->planSubscriptions()
            ->with('plan')
            ->where('provider', 'stripe')
            ->latest('starts_at')
            ->first();





        $stripeSnapshot =
            $this->stripeBilling->getSnapshot(
                $tenant
            );



        $currentPlan = $this->resolveCurrentPlan(
            stripeSnapshot: $stripeSnapshot,
            localActivePlan: $localActiveSubscription?->plan,
            localLastStripePlan: $localLastStripeSubscription?->plan,
        );

        $result = new BillingSummaryData(
            currentPlan: $currentPlan?->code?->value
                ?? PlanCode::Free->value,

            currentPlanName: $currentPlan?->name
                ?? 'Free',

            currentSubscription: $this->buildCurrentSubscription(
                stripeSnapshot: $stripeSnapshot,
                localSubscription: $localLastStripeSubscription,
            ),

            paymentMethod: $stripeSnapshot->paymentMethod,

            billingDetails: $this->buildBillingDetails(
                stripeSnapshot: $stripeSnapshot,
                tenant: $tenant,
                user: $user,
            ),

            actions: [
                'can_manage_portal' => $user?->can(PermissionName::BillingManage->value) ?? false,
            ],

            stripe: [
                'customer_exists' =>
                $stripeSnapshot->customerExists,

                'subscription_exists' =>
                $this->subscriptionExists(
                    tenant: $tenant,
                    stripeSnapshot: $stripeSnapshot,
                ),
            ],

            plans: $this->buildPlans(),
        );



        return $result;
    }

    private function resolveCurrentPlan(
        StripeBillingSnapshot $stripeSnapshot,
        ?Plan $localActivePlan,
        ?Plan $localLastStripePlan,
    ): ?Plan {
        // Stripe respondió correctamente. Stripe es la fuente autoritativa.
        if ($stripeSnapshot->subscriptionLookupSucceeded) {
            $remoteCurrent = $stripeSnapshot->currentSubscription;

            // No hay suscripción vigente en Stripe
            if (! $remoteCurrent) {
                return $this->planResolver->freePlan();
            }

            // Resolver el plan por el Price ID remoto
            $remotePlan = $this->planResolver->resolveByPriceIds($remoteCurrent->priceIds);

            if ($remotePlan) {
                return $remotePlan;
            }

            // Hay suscripción activa, pero el Price ID no coincide con config/billing.php
            logger()->error('Stripe active subscription price does not match a configured plan.', [
                'stripe_subscription_id' => $remoteCurrent->id,
                'stripe_price_ids' => $remoteCurrent->priceIds,
            ]);

            return $localActivePlan
                ?? $localLastStripePlan
                ?? $this->planResolver->freePlan();
        }

        // Stripe no respondió. Usar base local como fallback.
        return $localActivePlan
            ?? $this->planResolver->freePlan();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildCurrentSubscription(
        StripeBillingSnapshot $stripeSnapshot,
        mixed $localSubscription,
    ): ?array {
        if ($stripeSnapshot->subscriptionLookupSucceeded) {
            $remoteSubscription = $stripeSnapshot->displaySubscription;

            if (! $remoteSubscription) {
                return null;
            }

            return $this->serializeRemoteSubscription(
                subscription: $remoteSubscription,
                localFallbackPlan: $localSubscription?->plan,
            );
        }

        return $this->serializeLocalSubscription($localSubscription);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRemoteSubscription(
        StripeSubscriptionData $subscription,
        ?Plan $localFallbackPlan,
    ): array {
        $plan = $this->planResolver->resolveByPriceIds($subscription->priceIds)
            ?? $localFallbackPlan;

        return [
            'plan' => $plan?->code?->value,
            'plan_name' => $plan?->name ?? 'Plan no configurado',
            'plan_price' => $plan?->monthly_price,
            'status' => $subscription->normalizedStatus(),
            'current_period_ends_at' => $subscription->currentPeriodEndsAt,
            'cancel_at_period_end' => $subscription->status === 'canceled'
                ? false
                : $subscription->cancelAtPeriodEnd,
            'past_due' => $subscription->isPastDue(),
            'ended_at' => $subscription->endedAt,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeLocalSubscription(mixed $subscription): ?array
    {
        if (! $subscription) {
            return null;
        }

        return [
            'plan' => $subscription->plan?->code?->value,
            'plan_name' => $subscription->plan?->name,
            'plan_price' => $subscription->plan?->monthly_price,
            'status' => $subscription->status->value,
            'current_period_ends_at' => $subscription->current_period_ends_at,
            'cancel_at_period_end' => false,
            'past_due' => $subscription->status->value === 'past_due',
            'ended_at' => $subscription->ended_at,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildBillingDetails(
        StripeBillingSnapshot $stripeSnapshot,
        Tenant $tenant,
        ?Authenticatable $user,
    ): ?array {
        if (! $stripeSnapshot->customerExists) {
            return null;
        }

        $details = $stripeSnapshot->billingDetails ?? [];

        return [
            'name' => $details['name'] ?? $tenant->name,
            'email' => $details['email'] ?? $user?->email,
            'phone' => $details['phone'] ?? null,
            'address' => $details['address'] ?? null,
        ];
    }


    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildPlans(): array
    {
        return $this->planResolver->activePlans()
            ->map(fn(Plan $plan): array => [
                'code' => $plan->code->value,
                'name' => $plan->name,
                'monthly_price' => $plan->monthly_price,
                'max_users' => $plan->max_users,
                'max_products' => $plan->max_products,
                'monthly_sales_limit' => $plan->monthly_sales_limit,
                'included_branches' => $plan->included_branches,
                'included_stamps' => $plan->included_stamps,
                'additional_stamp_price' => $plan->additional_stamp_price,
                'additional_branch_price' => $plan->additional_branch_price,
                'allows_table_orders' => $plan->allows_table_orders,
                'allows_cash_shifts' => $plan->allows_cash_shifts,
                'allows_inventory' => $plan->allows_inventory,
                'allows_invoicing' => $plan->allows_invoicing,
            ])
            ->values()
            ->all();
    }

    private function subscriptionExists(
        Tenant $tenant,
        StripeBillingSnapshot $stripeSnapshot,
    ): bool {
        // Stripe respondió: usar resultado remoto
        if ($stripeSnapshot->subscriptionLookupSucceeded) {
            return $stripeSnapshot->displaySubscription !== null;
        }

        // Stripe falló: usar Cashier local como fallback
        return $tenant->subscriptions()->exists();
    }
}
