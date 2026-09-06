<?php

namespace App\Domain\Billing;

use App\Enums\PlanCode;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servicio compartido de sincronización de suscripciones con Stripe.
 *
 * Puede ser invocado tanto desde el gateway (cambio de plan inmediato)
 * como desde el listener de webhooks (confirmación asíncrona).
 * No depende del contexto HTTP, recibe explícitamente el Tenant.
 */
final class SyncStripeSubscriptionService
{
    /**
     * Sincroniza el estado de una suscripción de Stripe con la BD local.
     *
     * @param  Tenant  $tenant  Tenant al que pertenece la suscripción.
     * @param  \Stripe\Subscription  $stripeSubscription  Objeto devuelto por Stripe.
     * @param  int|null  $eventCreatedAt  Timestamp del evento de Stripe (para control de versión).
     */
    public function sync(
        Tenant $tenant,
        \Stripe\Subscription $stripeSubscription,
        ?int $eventCreatedAt = null,
    ): void {
        DB::transaction(function () use ($tenant, $stripeSubscription, $eventCreatedAt): void {
            $status = $this->statusFor((string) ($stripeSubscription->status ?? ''));
            $planCode = $this->planCodeFor($stripeSubscription);

            // Si Stripe reporta status cancelado, delegar a syncDeleted
            if ($status === SubscriptionStatus::Cancelled) {
                $this->cancelAndRestoreFree($tenant, $stripeSubscription);

                return;
            }

            if ($planCode === null) {
                Log::error('Stripe sync: price_id no reconocido en ningún plan configurado', [
                    'stripe_subscription_id' => $stripeSubscription->id,
                    'tenant_id' => $tenant->id,
                    'price_ids' => collect($stripeSubscription->items->data ?? [])->pluck('price.id')->all(),
                ]);

                return;
            }

            $plan = Plan::query()
                ->where('code', $planCode->value)
                ->where('is_active', true)
                ->firstOrFail();

            // Obtener el registro local existente, si hay
            $existing = PlanSubscription::query()
                ->where('stripe_subscription_id', $stripeSubscription->id)
                ->first();

            // Control de versión: no sobrescribir con un evento más antiguo
            if ($existing && $eventCreatedAt !== null && $existing->last_stripe_event_created_at !== null) {
                $existingEventTimestamp = $existing->last_stripe_event_created_at->timestamp;

                if ($eventCreatedAt < $existingEventTimestamp) {
                    Log::info('Stripe sync: evento ignorado por ser más antiguo', [
                        'stripe_subscription_id' => $stripeSubscription->id,
                        'event_created_at' => $eventCreatedAt,
                        'last_processed_at' => $existingEventTimestamp,
                    ]);

                    return;
                }
            }

            $oldValues = $existing ? $this->snapshot($existing) : [];

            // Cancelar otros registros locales activos (no en Stripe)
            PlanSubscription::query()
                ->where('tenant_id', $tenant->getKey())
                ->where('stripe_subscription_id', '!=', $stripeSubscription->id)
                ->whereIn('status', [
                    SubscriptionStatus::Trial->value,
                    SubscriptionStatus::Active->value,
                    SubscriptionStatus::PastDue->value,
                ])
                ->update([
                    'status' => SubscriptionStatus::Cancelled,
                    'cancelled_at' => now(),
                ]);

            // $startsAt = $this->timestamp($stripeSubscription->start_date ?? null) ?? now();
            // $periodStart = $this->periodTimestamp($stripeSubscription, 'current_period_start') ?? $startsAt;
            // $periodEnd = $this->periodTimestamp($stripeSubscription, 'current_period_end')
            //     ?? $periodStart->copy()->addMonthNoOverflow();

            $startsAt = $this->timestamp(
                $stripeSubscription->start_date ?? null
            ) ?? now();

            [
                $periodStart,
                $periodEnd,
            ] = $this->billingPeriodFor(
                subscription: $stripeSubscription,
                plan: $planCode,
            );

            // Determinar estado local:
            // - Si cancel_at_period_end = true, sigue activa pero marcaremos cancelled_at
            $cancelAtPeriodEnd = (bool) ($stripeSubscription->cancel_at_period_end ?? false);
            $localStatus = $cancelAtPeriodEnd ? SubscriptionStatus::Active : $status;
            $cancelledAt = $cancelAtPeriodEnd
                ? $this->timestamp($stripeSubscription->canceled_at)
                : null;

            // ended_at solo se setea cuando Stripe reporta que la suscripción terminó
            // (status cancelled). Mientras está activa, ended_at = null.
            $endedAt = null;

            $subscription = PlanSubscription::query()->updateOrCreate(
                [
                    'tenant_id' => $tenant->getKey(),
                    'stripe_subscription_id' => $stripeSubscription->id,
                ],
                [
                    'plan_id' => $plan->getKey(),
                    'provider' => 'stripe',
                    'stripe_price_id' => $this->basePriceFor($planCode),
                    'status' => $localStatus,
                    'monthly_price_snapshot' => $plan->monthly_price,
                    'entitlements_snapshot' => $plan->entitlements(),
                    'starts_at' => $startsAt,
                    'current_period_starts_at' => $periodStart,
                    'current_period_ends_at' => $periodEnd,
                    'cancelled_at' => $cancelledAt,
                    'ended_at' => $endedAt,
                    'last_stripe_event_created_at' => $eventCreatedAt
                        ? Carbon::createFromTimestamp($eventCreatedAt)
                        : now(),
                ],
            );

            $newValues = $this->snapshot($subscription);

            if ($oldValues !== $newValues) {
                $this->audit($tenant, 'billing.subscription_synced', $oldValues, $newValues);
            }
        });
    }

    /**
     * Sincroniza una suscripción que Stripe reporta como eliminada/terminada.
     *
     * @param  Tenant  $tenant  Tenant al que pertenece la suscripción.
     * @param  \Stripe\Subscription  $stripeSubscription  Objeto devuelto por Stripe.
     */
    public function syncDeleted(
        Tenant $tenant,
        \Stripe\Subscription $stripeSubscription,
    ): void {
        DB::transaction(function () use ($tenant, $stripeSubscription): void {
            $this->cancelAndRestoreFree($tenant, $stripeSubscription);
        });
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    private function audit(Tenant $tenant, string $action, array $oldValues, array $newValues): void
    {
        \App\Models\AuditEvent::query()->create([
            'actor_user_id' => null,
            'action' => $action,
            'auditable_type' => Tenant::class,
            'auditable_public_id' => $tenant->public_id,
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'request_id' => request()->attributes->get('request_id'),
            'ip_address' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 1024),
        ]);
    }

    private function planCodeFor(\Stripe\Subscription $subscription): ?PlanCode
    {
        $items = $subscription->items->data
            ?? data_get($subscription->toArray(), 'items.data', []);

        $prices = collect($items)
            ->map(fn(mixed $item): mixed => data_get($item, 'price.id'))
            ->filter()
            ->all();

        foreach ([PlanCode::Business, PlanCode::Restaurant] as $plan) {
            if (in_array($this->basePriceFor($plan), $prices, true)) {
                return $plan;
            }
        }

        return null;
    }

    private function basePriceFor(PlanCode $plan): string
    {
        return (string) config('billing.plans.' . $plan->value . '.price');
    }

    private function statusFor(string $stripeStatus): SubscriptionStatus
    {
        return match ($stripeStatus) {
            'trialing' => SubscriptionStatus::Trial,
            'active' => SubscriptionStatus::Active,
            'past_due', 'unpaid' => SubscriptionStatus::PastDue,
            default => SubscriptionStatus::Cancelled,
        };
    }

    /**
     * Marca la suscripción como cancelada con datos reales de Stripe
     * y restaura el plan Free si no hay otra suscripción activa.
     */
    private function cancelAndRestoreFree(
        Tenant $tenant,
        \Stripe\Subscription $stripeSubscription,
    ): void {
        $endedAt = $this->timestamp($stripeSubscription->ended_at);
        $cancelledAt = $this->timestamp($stripeSubscription->canceled_at);

        $subscription = PlanSubscription::query()
            ->where('stripe_subscription_id', $stripeSubscription->id)
            ->first();

        if ($subscription && $subscription->status !== SubscriptionStatus::Cancelled) {
            $oldValues = $this->snapshot($subscription);
            $subscription->update([
                'status' => SubscriptionStatus::Cancelled,
                'cancelled_at' => $cancelledAt,
                'ended_at' => $endedAt,
            ]);

            $this->audit($tenant, 'billing.subscription_cancelled', $oldValues, $this->snapshot($subscription));
        }

        $hasAnotherPaidPlan = PlanSubscription::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('stripe_subscription_id', '!=', $stripeSubscription->id)
            ->whereIn('status', [
                SubscriptionStatus::Trial->value,
                SubscriptionStatus::Active->value,
            ])
            ->where('provider', 'stripe')
            ->exists();

        if ($hasAnotherPaidPlan) {
            return;
        }

        $free = Plan::query()->where('code', PlanCode::Free->value)->firstOrFail();
        $hasActiveFreePlan = PlanSubscription::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('plan_id', $free->getKey())
            ->where('provider', 'local')
            ->where('status', SubscriptionStatus::Active->value)
            ->exists();

        if ($hasActiveFreePlan) {
            return;
        }

        // $periodStartsAt = now();

        // PlanSubscription::query()->create([
        //     'tenant_id' => $tenant->getKey(),
        //     'plan_id' => $free->getKey(),
        //     'provider' => 'local',
        //     'status' => SubscriptionStatus::Active,
        //     'monthly_price_snapshot' => $free->monthly_price,
        //     'entitlements_snapshot' => $free->entitlements(),
        //     'starts_at' => $periodStartsAt,
        //     'current_period_starts_at' => $periodStartsAt,
        //     'current_period_ends_at' => $periodStartsAt->copy()->addMonthNoOverflow(),
        // ]);

        $timezone =
            $tenant->timezone
            ?: config(
                'app.timezone',
                'UTC'
            );

        $now =
            now($timezone);

        $periodStartsAt =
            $now
            ->copy()
            ->startOfMonth()
            ->utc();

        $periodEndsAt =
            $now
            ->copy()
            ->addMonthNoOverflow()
            ->startOfMonth()
            ->utc();

        PlanSubscription::query()->create([
            'tenant_id' =>
            $tenant->getKey(),

            'plan_id' =>
            $free->getKey(),

            'provider' =>
            'local',

            'status' =>
            SubscriptionStatus::Active,

            'monthly_price_snapshot' =>
            $free->monthly_price,

            'entitlements_snapshot' =>
            $free->entitlements(),

            /*
     * starts_at representa cuándo
     * empezó realmente a usar Free.
     */
            'starts_at' =>
            now(),

            /*
     * El período comercial de Free
     * siempre es mes calendario.
     */
            'current_period_starts_at' =>
            $periodStartsAt,

            'current_period_ends_at' =>
            $periodEndsAt,
        ]);
    }

    /**
     * @return array{
     *     plan_id: int,
     *     status: string,
     *     current_period_starts_at: ?string,
     *     current_period_ends_at: ?string
     * }
     */
    private function snapshot(
        PlanSubscription $subscription
    ): array {
        return [
            'plan_id' =>
            (int) $subscription->plan_id,

            'status' =>
            $subscription->status->value,

            'current_period_starts_at' =>
            $subscription
                ->current_period_starts_at
                ?->toIso8601String(),

            'current_period_ends_at' =>
            $subscription
                ->current_period_ends_at
                ?->toIso8601String(),
        ];
    }

    // private function periodTimestamp(\Stripe\Subscription $subscription, string $key): ?Carbon
    // {
    //     return $this->timestamp(
    //         $subscription->{$key}
    //             ?? data_get($subscription->toArray(), $key)
    //             ?? data_get($subscription->toArray(), "items.data.0.{$key}"),
    //     );
    // }

    /**
     * Obtiene el período real de facturación
     * correspondiente al item del plan base.
     *
     * Compatible con:
     *
     * - API Stripe anterior:
     *   current_period_start/end en Subscription
     *
     * - API Stripe actual:
     *   current_period_start/end en SubscriptionItem
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function billingPeriodFor(
        \Stripe\Subscription $subscription,
        PlanCode $plan,
    ): array {
        /*
     * Compatibilidad con versiones anteriores
     * de la API de Stripe.
     */
        $subscriptionArray =
            $subscription->toArray();

        $topLevelStart =
            $this->timestamp(
                $subscription->current_period_start
                    ?? data_get(
                        $subscriptionArray,
                        'current_period_start'
                    )
            );

        $topLevelEnd =
            $this->timestamp(
                $subscription->current_period_end
                    ?? data_get(
                        $subscriptionArray,
                        'current_period_end'
                    )
            );

        if (
            $topLevelStart !== null &&
            $topLevelEnd !== null
        ) {
            return [
                $topLevelStart,
                $topLevelEnd,
            ];
        }

        /*
     * Stripe actual maneja el período
     * a nivel SubscriptionItem.
     *
     * Como Kova tiene:
     *
     * - item del plan base
     * - item metered de timbres
     *
     * debemos buscar específicamente
     * el item correspondiente al plan.
     */
        $basePriceId =
            $this->basePriceFor($plan);

        $items =
            $subscription->items->data
            ?? data_get(
                $subscriptionArray,
                'items.data',
                []
            );

        foreach ($items as $item) {
            $priceId =
                data_get(
                    $item,
                    'price.id'
                );

            if (
                $priceId !==
                $basePriceId
            ) {
                continue;
            }

            $periodStart =
                $this->timestamp(
                    data_get(
                        $item,
                        'current_period_start'
                    )
                );

            $periodEnd =
                $this->timestamp(
                    data_get(
                        $item,
                        'current_period_end'
                    )
                );

            if (
                $periodStart !== null &&
                $periodEnd !== null
            ) {
                return [
                    $periodStart,
                    $periodEnd,
                ];
            }
        }

        /*
     * No inventamos un período.
     *
     * Si Stripe no entregó el ciclo,
     * es mejor detener la sincronización
     * que guardar fechas incorrectas.
     */
        Log::error(
            'Stripe sync: no fue posible resolver el período de facturación.',
            [
                'stripe_subscription_id' =>
                $subscription->id,

                'plan' =>
                $plan->value,

                'base_price_id' =>
                $basePriceId,
            ]
        );

        throw new \RuntimeException(
            'No fue posible determinar el período de facturación de Stripe.'
        );
    }

    private function timestamp(mixed $value): ?Carbon
    {
        return is_int($value) || ctype_digit((string) $value)
            ? Carbon::createFromTimestamp((int) $value)
            : null;
    }
}
