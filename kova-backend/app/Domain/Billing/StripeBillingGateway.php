<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Contracts\BillingGateway;
use App\Enums\PlanCode;
use App\Models\Tenant;
use App\Models\User;
use DomainException;
use RuntimeException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;


class StripeBillingGateway implements BillingGateway
{
    private \Stripe\StripeClient $stripe;

    public function __construct(
        private readonly SyncStripeSubscriptionService $syncService,
    ) {
        $this->stripe = new \Stripe\StripeClient(config('cashier.secret'));

        \Stripe\Stripe::setMaxNetworkRetries(2);
    }

    private const META_CHANGE_ID =
    'app_plan_change_id';

    private const META_TARGET_PRICE =
    'app_target_price';

    private const META_RESUME_AFTER_UPGRADE =
    'app_resume_after_upgrade';

    public function createCheckout(
        Tenant $tenant,
        User $user,
        PlanCode $plan,
        string $operationId,
    ): string {
        $operationId = trim($operationId);

        if ($operationId === '') {
            throw new DomainException(
                'Falta el identificador de la operación.'
            );
        }

        if ($plan === PlanCode::Free) {
            throw new DomainException(
                'El plan gratuito no requiere un proceso de pago.'
            );
        }

        $basePrice = $this->priceFor($plan);

        $frontendUrl = rtrim(
            (string) config('app.frontend_url'),
            '/'
        );

        $billingUrl = $frontendUrl
            . '/'
            . $tenant->slug
            . '/billing';

        $subscription = $tenant->subscription('default');

        if ($subscription && ! $subscription->ended()) {
            return $this->changePlan(
                tenant: $tenant,
                subscription: $subscription,
                newPriceId: $basePrice,
                targetPlan: $plan,
                billingUrl: $billingUrl,
                operationId: $operationId,
            );
        }

        return $this->newSubscriptionCheckout(
            tenant: $tenant,
            user: $user,
            basePrice: $basePrice,
            billingUrl: $billingUrl,
            operationId: $operationId,
        );
    }

    public function createPortal(Tenant $tenant): string
    {
        if (! $tenant->hasStripeId()) {
            throw new DomainException('El negocio todavía no tiene una cuenta de cobro.');
        }

        $returnUrl = rtrim((string) config('app.frontend_url'), '/')
            . '/' . $tenant->slug . '/billing';

        return $tenant->billingPortalUrl($returnUrl);
    }

    public function cancelSubscription(Tenant $tenant): void
    {
        $subscription = $tenant->subscription('default');

        if (! $subscription || $subscription->ended()) {
            throw new DomainException('No hay una suscripción activa para cancelar.');
        }

        $stripeSubscription = $subscription->asStripeSubscription();

        if ($stripeSubscription->status !== 'active' && $stripeSubscription->status !== 'trialing') {
            throw new DomainException('La suscripción no está en un estado que permita cancelación.');
        }

        if ($stripeSubscription->cancel_at_period_end) {
            throw new DomainException('La suscripción ya está programada para cancelarse.');
        }

        $this->stripe->subscriptions->update($stripeSubscription->id, [
            'cancel_at_period_end' => true,
        ]);
    }

    public function reactivateSubscription(Tenant $tenant): void
    {
        $subscription = $tenant->subscription('default');

        if (! $subscription || $subscription->ended()) {
            throw new DomainException('No hay una suscripción para reactivar.');
        }

        $stripeSubscription = $subscription->asStripeSubscription();

        if (! $stripeSubscription->cancel_at_period_end) {
            throw new DomainException('La suscripción no está programada para cancelarse.');
        }

        $this->stripe->subscriptions->update($stripeSubscription->id, [
            'cancel_at_period_end' => false,
        ]);
    }

    /**
     * Maneja el cambio de plan para suscripciones existentes.
     *
     * Ambos upgrades y downgrades son inmediatos con prorrateo.
     * Stripe calcula el cargo o crédito proporcional automáticamente.
     */
    private function changePlan(
        Tenant $tenant,
        mixed $subscription,
        string $newPriceId,
        PlanCode $targetPlan,
        string $billingUrl,
        string $operationId,
    ): string {
        $currentPlan = $this->resolveCurrentPlan($subscription);

        if ($currentPlan === null) {
            throw new DomainException('No pudimos identificar el plan actual en Stripe.');
        }

        // Ya tiene este plan
        if ($currentPlan === $newPriceId) {
            throw new DomainException('El negocio ya cuenta con este plan.');
        }

        // Determinar si es upgrade o downgrade por nivel, no por precio
        $currentPlanCode = $this->resolveCurrentPlanCode($subscription);
        $isUpgrade = $currentPlanCode !== null && $targetPlan->level() > $currentPlanCode->level();

        if ($isUpgrade) {
            return $this->upgradePlanImmediately(
                tenant: $tenant,
                subscription: $subscription,
                newPriceId: $newPriceId,
                billingUrl: $billingUrl,
                operationId: $operationId,
            );
        }

        return $this->downgradePlanImmediately(
            tenant: $tenant,
            subscription: $subscription,
            newPriceId: $newPriceId,
            billingUrl: $billingUrl,
            operationId: $operationId,
        );
    }

    /**
     * Upgrade inmediato: cambia el precio y cobra la diferencia inmediatamente.
     * Si el pago falla, el cambio queda pendiente hasta que se complete.
     */

    private function retrieveStripeSubscription(
        string $stripeSubscriptionId,
    ): \Stripe\Subscription {
        try {
            return $this->stripe
                ->subscriptions
                ->retrieve(
                    $stripeSubscriptionId,
                    [
                        'expand' => [
                            'latest_invoice',
                        ],
                    ],
                );
        } catch (
            \Stripe\Exception\ApiErrorException $exception
        ) {
            Log::error(
                'No fue posible consultar la suscripción en Stripe.',
                [
                    'stripe_subscription_id' =>
                    $stripeSubscriptionId,

                    'message' =>
                    $exception->getMessage(),

                    'stripe_code' =>
                    $exception->getStripeCode(),
                ],
            );

            throw new DomainException(
                'No pudimos consultar tu suscripción. Intenta de nuevo.'
            );
        }
    }

    private function assertPendingUpdatesAreSupported(
        \Stripe\Subscription $subscription,
    ): void {
        if (
            $subscription->collection_method
            !== 'charge_automatically'
        ) {
            throw new DomainException(
                'Esta suscripción no permite mejoras automáticas. Revisa tu método de facturación.'
            );
        }
    }

    private function findBasePlanItem(
        \Stripe\Subscription $subscription,
    ): ?object {
        $basePriceIds = array_values(
            array_filter([
                $this->priceFor(
                    PlanCode::Business
                ),
                $this->priceFor(
                    PlanCode::Restaurant
                ),
            ])
        );

        foreach (
            data_get(
                $subscription,
                'items.data',
                []
            ) as $item
        ) {
            $priceId = data_get(
                $item,
                'price.id'
            );

            if (
                is_string($priceId)
                && in_array(
                    $priceId,
                    $basePriceIds,
                    true
                )
            ) {
                return $item;
            }
        }

        return null;
    }

    private function subscriptionContainsPrice(
        \Stripe\Subscription $subscription,
        string $expectedPriceId,
    ): bool {
        foreach (
            data_get(
                $subscription,
                'items.data',
                []
            ) as $item
        ) {
            if (
                data_get($item, 'price.id')
                === $expectedPriceId
            ) {
                return true;
            }
        }

        return false;
    }

    private function reactivateRenewalIfNeeded(
        Tenant $tenant,
        \Stripe\Subscription $subscription,
        string $operationId,
    ): \Stripe\Subscription {
        if (
            ! (bool) (
                $subscription
                ->cancel_at_period_end
                ?? false
            )
        ) {
            return $subscription;
        }

        if ($subscription->status === 'canceled') {
            throw new DomainException(
                'La suscripción ya terminó y no puede reactivarse.'
            );
        }

        try {
            return $this->stripe
                ->subscriptions
                ->update(
                    $subscription->id,
                    [
                        /*
                     * Esta petición está separada del upgrade.
                     * No lleva pending_if_incomplete.
                     */
                        'cancel_at_period_end' =>
                        false,

                        'metadata' => [
                            self::META_RESUME_AFTER_UPGRADE =>
                            '0',

                            'app_last_plan_change_id' =>
                            $operationId,
                        ],
                    ],
                    [
                        'idempotency_key' =>
                        $this->stripeIdempotencyKey(
                            action: 'resume-after-upgrade',

                            tenantId: (string) $tenant->id,

                            subscriptionId: $subscription->id,

                            operationId: $operationId,
                        ),
                    ],
                );
        } catch (
            \Stripe\Exception\ApiErrorException $exception
        ) {
            Log::error(
                'No se pudo reactivar la renovación después del upgrade.',
                [
                    'tenant_id' =>
                    $tenant->id,

                    'stripe_subscription_id' =>
                    $subscription->id,

                    'operation_id' =>
                    $operationId,

                    'message' =>
                    $exception->getMessage(),

                    'stripe_code' =>
                    $exception->getStripeCode(),
                ],
            );

            throw new DomainException(
                'El plan fue actualizado, pero la renovación continúa programada para cancelarse.'
            );
        }
    }

    private function resolvePendingInvoiceUrl(
        \Stripe\Subscription $subscription,
    ): string {
        $latestInvoice =
            $subscription->latest_invoice
            ?? null;

        if (is_object($latestInvoice)) {
            $invoiceUrl =
                $latestInvoice
                ->hosted_invoice_url
                ?? null;

            if (
                is_string($invoiceUrl)
                && $invoiceUrl !== ''
            ) {
                return $invoiceUrl;
            }

            $latestInvoice =
                $latestInvoice->id
                ?? null;
        }

        if (
            is_string($latestInvoice)
            && $latestInvoice !== ''
        ) {
            try {
                $invoice = $this->stripe
                    ->invoices
                    ->retrieve(
                        $latestInvoice,
                        [],
                    );

                $invoiceUrl =
                    $invoice
                    ->hosted_invoice_url
                    ?? null;

                if (
                    is_string($invoiceUrl)
                    && $invoiceUrl !== ''
                ) {
                    return $invoiceUrl;
                }
            } catch (
                \Stripe\Exception\ApiErrorException $exception
            ) {
                Log::warning(
                    'No fue posible recuperar la factura del cambio pendiente.',
                    [
                        'stripe_subscription_id' =>
                        $subscription->id,

                        'stripe_invoice_id' =>
                        $latestInvoice,

                        'message' =>
                        $exception->getMessage(),
                    ],
                );
            }
        }

        throw new DomainException(
            'El cambio de plan requiere completar un pago. Revisa tus facturas o método de pago.'
        );
    }

    private function stripeIdempotencyKey(
        string $action,
        string $tenantId,
        string $subscriptionId,
        string $operationId,
    ): string {
        $payload = implode('|', [
            $action,
            $tenantId,
            $subscriptionId,
            $operationId,
        ]);

        return 'billing:'
            . $action
            . ':'
            . hash('sha256', $payload);
    }

    public function finalizeSubscriptionChangeFromWebhook(
        Tenant $tenant,
        \Stripe\Subscription $subscription,
    ): void {
        /*
     * Todavía existe un pago pendiente.
     * No reactivamos ni provisionamos el plan nuevo.
     */
        if ($subscription->pending_update !== null) {
            $this->syncService->sync(
                $tenant,
                $subscription,
            );

            return;
        }

        $shouldResume = data_get(
            $subscription,
            'metadata.'
                . self::META_RESUME_AFTER_UPGRADE
        ) === '1';

        $operationId = data_get(
            $subscription,
            'metadata.'
                . self::META_CHANGE_ID
        );

        if (
            $shouldResume
            && (bool) (
                $subscription
                ->cancel_at_period_end
                ?? false
            )
        ) {
            $subscription =
                $this->reactivateRenewalIfNeeded(
                    tenant: $tenant,
                    subscription: $subscription,
                    operationId: is_string($operationId)
                        && $operationId !== ''
                        ? $operationId
                        : $subscription->id,
                );
        }

        $this->syncService->sync(
            $tenant,
            $subscription,
        );
    }

    private function upgradePlanImmediately(
        Tenant $tenant,
        mixed $subscription,
        string $newPriceId,
        string $billingUrl,
        string $operationId,
    ): string {
        $stripeSubscription =
            $this->retrieveStripeSubscription(
                $subscription->stripe_id
            );

        $this->assertPendingUpdatesAreSupported(
            $stripeSubscription
        );

        if ($stripeSubscription->pending_update !== null) {
            return $this->resolvePendingInvoiceUrl(
                $stripeSubscription
            );
        }

        $baseItem = $this->findBasePlanItem(
            $stripeSubscription
        );

        if (! $baseItem) {
            throw new DomainException(
                'No pudimos identificar el plan actual en Stripe.'
            );
        }

        $currentPriceId = data_get(
            $baseItem,
            'price.id'
        );

        /*
     * La operación ya se aplicó previamente.
     */
        if ($currentPriceId === $newPriceId) {
            $finalSubscription =
                $this->reactivateRenewalIfNeeded(
                    tenant: $tenant,
                    subscription: $stripeSubscription,
                    operationId: $operationId,
                );

            $this->syncService->sync(
                $tenant,
                $finalSubscription,
            );

            return $billingUrl
                . '?checkout=success';
        }

        /*
     * Stripe NO permite crear un pending_update (payment_behavior=pending_if_incomplete)
     * en una suscripción que tiene cancel_at_period_end = true.
     *
     * Primero: quitamos la cancelación programada en una llamada SEPARADA
     * (sin pending_if_incomplete), para que Stripe lo permita.
     *
     * Ref: https://stripe.com/docs/billing/subscriptions/pending-updates-reference#supported-attributes
     */
        $hadCancelAtPeriodEnd = (bool) (
            $stripeSubscription
            ->cancel_at_period_end
            ?? false
        );

        if ($hadCancelAtPeriodEnd) {
            try {
                $stripeSubscription =
                    $this->stripe
                    ->subscriptions
                    ->update(
                        $stripeSubscription->id,
                        [
                            'cancel_at_period_end' => false,
                        ],
                        [
                            'idempotency_key' =>
                            $this->stripeIdempotencyKey(
                                action: 'remove-cancel-before-upgrade',
                                tenantId: (string) $tenant->id,
                                subscriptionId: $stripeSubscription->id,
                                operationId: $operationId,
                            ),
                        ],
                    );
            } catch (
                \Stripe\Exception\ApiErrorException $exception
            ) {
                Log::error(
                    'No se pudo remover cancel_at_period_end antes del upgrade.',
                    [
                        'tenant_id' => $tenant->id,
                        'stripe_subscription_id' =>
                        $stripeSubscription->id,
                        'operation_id' =>
                        $operationId,
                        'message' =>
                        $exception->getMessage(),
                        'stripe_code' =>
                        $exception->getStripeCode(),
                    ],
                );

                throw new DomainException(
                    'No pudimos preparar la suscripción para la mejora de plan. Intenta de nuevo.'
                );
            }
        }

        try {
            $updatedSubscription =
                $this->stripe
                ->subscriptions
                ->update(
                    $stripeSubscription->id,
                    [
                        'items' => [[
                            'id' => $baseItem->id,
                            'price' => $newPriceId,
                        ]],

                        'proration_behavior' =>
                        'always_invoice',

                        'payment_behavior' =>
                        'pending_if_incomplete',

                        'metadata' => [
                            self::META_CHANGE_ID =>
                            $operationId,

                            self::META_TARGET_PRICE =>
                            $newPriceId,

                            self::META_RESUME_AFTER_UPGRADE =>
                            $hadCancelAtPeriodEnd
                                ? '1'
                                : '0',
                        ],

                        'expand' => [
                            'latest_invoice',
                        ],
                    ],
                    [
                        'idempotency_key' =>
                        $this->stripeIdempotencyKey(
                            action: 'upgrade',
                            tenantId: (string) $tenant->id,
                            subscriptionId: $stripeSubscription->id,
                            operationId: $operationId,
                        ),
                    ],
                );
        } catch (
            \Stripe\Exception\ApiErrorException $exception
        ) {
            Log::error(
                'Error al hacer upgrade de plan',
                [
                    'tenant_id' => $tenant->id,

                    'stripe_subscription_id' =>
                    $stripeSubscription->id,

                    'current_price_id' =>
                    $currentPriceId,

                    'new_price_id' =>
                    $newPriceId,

                    'operation_id' =>
                    $operationId,

                    'message' =>
                    $exception->getMessage(),

                    'stripe_code' =>
                    $exception->getStripeCode(),
                ],
            );

            throw new DomainException(
                'No pudimos procesar la mejora de plan. Intenta de nuevo.'
            );
        }

        /*
     * La factura no se completó.
     *
     * Stripe mantiene el precio anterior y almacena el nuevo
     * precio dentro de pending_update.
     */
        if ($updatedSubscription->pending_update !== null) {
            return $this->resolvePendingInvoiceUrl(
                $updatedSubscription
            );
        }

        /*
     * Comprobación defensiva:
     * la respuesta debe contener ya el nuevo precio.
     */
        if (
            ! $this->subscriptionContainsPrice(
                $updatedSubscription,
                $newPriceId,
            )
        ) {
            Log::critical(
                'Stripe respondió sin pending_update pero el precio esperado no está aplicado.',
                [
                    'tenant_id' => $tenant->id,

                    'stripe_subscription_id' =>
                    $updatedSubscription->id,

                    'expected_price_id' =>
                    $newPriceId,

                    'operation_id' =>
                    $operationId,
                ],
            );

            throw new DomainException(
                'Stripe procesó la operación, pero no pudimos confirmar el nuevo plan.'
            );
        }

        /*
     * El pago ya terminó y el plan fue aplicado.
     *
     * Si previamente habíamos quitado cancel_at_period_end, ya no
     * necesitamos reactivar nada. Si no tenía cancelación previa,
     * reactivateRenewalIfNeeded es un no-op.
     */
        try {
            $finalSubscription =
                $this->reactivateRenewalIfNeeded(
                    tenant: $tenant,
                    subscription: $updatedSubscription,
                    operationId: $operationId,
                );
        } catch (DomainException $exception) {
            Log::warning(
                'El upgrade fue aplicado, pero la reactivación quedó pendiente.',
                [
                    'tenant_id' => $tenant->id,

                    'stripe_subscription_id' =>
                    $updatedSubscription->id,

                    'operation_id' =>
                    $operationId,

                    'message' =>
                    $exception->getMessage(),
                ],
            );

            $this->syncService->sync(
                $tenant,
                $updatedSubscription,
            );

            return $billingUrl
                . '?checkout=success'
                . '&renewal=pending';
        }

        $this->syncService->sync(
            $tenant,
            $finalSubscription,
        );

        return $billingUrl
            . '?checkout=success';
    }

    /**
     * Downgrade inmediato: cambia el precio y genera crédito prorrateado.
     * No intenta cobrar inmediatamente; el crédito se aplica a la próxima factura.
     */
    private function downgradePlanImmediately(
        Tenant $tenant,
        mixed $subscription,
        string $newPriceId,
        string $billingUrl,
        string $operationId,
    ): string {
        $stripeSubscription = $this->retrieveStripeSubscription(
            $subscription->stripe_id
        );

        $baseItem = $this->findBasePlanItem(
            $stripeSubscription
        );

        if (! $baseItem) {
            throw new DomainException(
                'No pudimos identificar el plan actual en Stripe.'
            );
        }

        $currentPriceId = data_get(
            $baseItem,
            'price.id'
        );

        /*
     * El downgrade ya fue aplicado.
     *
     * Aun así, comprobamos que la renovación quede activa,
     * por si la función fue ejecutada nuevamente.
     */
        if ($currentPriceId === $newPriceId) {
            $finalSubscription = $stripeSubscription;

            if (
                (bool) (
                    $stripeSubscription->cancel_at_period_end
                    ?? false
                )
            ) {
                try {
                    $finalSubscription = $this->stripe
                        ->subscriptions
                        ->update(
                            $stripeSubscription->id,
                            [
                                'cancel_at_period_end' => false,
                            ],
                            [
                                'idempotency_key' =>
                                $this->stripeIdempotencyKey(
                                    action: 'reactivate-after-downgrade',
                                    tenantId: (string) $tenant->id,
                                    subscriptionId: $stripeSubscription->id,
                                    operationId: $operationId,
                                ),
                            ],
                        );
                } catch (
                    \Stripe\Exception\ApiErrorException $exception
                ) {
                    Log::error(
                        'No se pudo quitar la cancelación programada del downgrade.',
                        [
                            'tenant_id' => $tenant->id,

                            'stripe_subscription_id' =>
                            $stripeSubscription->id,

                            'operation_id' =>
                            $operationId,

                            'message' =>
                            $exception->getMessage(),

                            'stripe_code' =>
                            $exception->getStripeCode(),
                        ],
                    );

                    throw new DomainException(
                        'El plan ya fue actualizado, pero no pudimos reactivar su renovación.'
                    );
                }
            }

            $this->syncService->sync(
                $tenant,
                $finalSubscription,
            );

            return $billingUrl
                . '?checkout=success';
        }

        try {
            $updatedSubscription = $this->stripe
                ->subscriptions
                ->update(
                    $stripeSubscription->id,
                    [
                        'items' => [[
                            'id' => $baseItem->id,
                            'price' => $newPriceId,
                        ]],

                        /*
                     * Cambiar el plan también reactiva la renovación.
                     */
                        'cancel_at_period_end' => false,

                        /*
                     * Stripe genera el crédito del downgrade,
                     * pero no crea una factura inmediatamente.
                     */
                        'proration_behavior' =>
                        'create_prorations',

                        'metadata' => [
                            self::META_CHANGE_ID =>
                            $operationId,

                            self::META_TARGET_PRICE =>
                            $newPriceId,
                        ],
                    ],
                    [
                        'idempotency_key' =>
                        $this->stripeIdempotencyKey(
                            action: 'downgrade',
                            tenantId: (string) $tenant->id,
                            subscriptionId: $stripeSubscription->id,
                            operationId: $operationId,
                        ),
                    ],
                );
        } catch (
            \Stripe\Exception\ApiErrorException $exception
        ) {
            Log::error(
                'Error al hacer downgrade de plan',
                [
                    'tenant_id' => $tenant->id,

                    'stripe_subscription_id' =>
                    $stripeSubscription->id,

                    'current_price_id' =>
                    $currentPriceId,

                    'new_price_id' =>
                    $newPriceId,

                    'operation_id' =>
                    $operationId,

                    'cancel_at_period_end' =>
                    $stripeSubscription->cancel_at_period_end
                        ?? null,

                    'message' =>
                    $exception->getMessage(),

                    'stripe_code' =>
                    $exception->getStripeCode(),
                ],
            );

            throw new DomainException(
                'No pudimos procesar el cambio de plan. Intenta de nuevo.'
            );
        }

        /*
     * Comprobación defensiva:
     * confirmar que Stripe aplicó el nuevo precio.
     */
        if (
            ! $this->subscriptionContainsPrice(
                $updatedSubscription,
                $newPriceId,
            )
        ) {
            Log::critical(
                'Stripe procesó el downgrade, pero el precio esperado no está aplicado.',
                [
                    'tenant_id' => $tenant->id,

                    'stripe_subscription_id' =>
                    $updatedSubscription->id,

                    'expected_price_id' =>
                    $newPriceId,

                    'operation_id' =>
                    $operationId,
                ],
            );

            throw new DomainException(
                'Stripe procesó la operación, pero no pudimos confirmar el nuevo plan.'
            );
        }

        $this->syncService->sync(
            $tenant,
            $updatedSubscription,
        );

        return $billingUrl
            . '?checkout=success';
    }

    /**
     * Crea un checkout para una nueva suscripción.
     */
    private function newSubscriptionCheckout(
        Tenant $tenant,
        User $user,
        string $basePrice,
        string $billingUrl,
        string $operationId,
    ): string {
        $stampPrice = $this->configuredValue('billing.extras.stamp_price');

        $cacheKey = "checkout_lock_{$tenant->id}";

        if (Cache::has($cacheKey)) {
            throw new DomainException('Ya hay un checkout en proceso, espera un momento e intenta de nuevo.');
        }

        Cache::put($cacheKey, true, now()->addSeconds(10));

        try {
            $checkout = $tenant
                ->newSubscription('default', $basePrice)
                ->meteredPrice($stampPrice)
                ->withMetadata([
                    'tenant_public_id' => $tenant->public_id,
                    'plan' => 'new',
                ])
                ->checkout([
                    'success_url' => $billingUrl . '?checkout=success',
                    'cancel_url' => $billingUrl . '?checkout=cancelled',
                    'billing_address_collection' => 'required',
                    'tax_id_collection' => ['enabled' => true],
                    'customer_update' => [
                        'address' => 'auto',
                        'name' => 'auto',
                    ],
                ], [
                    'email' => $user->email,
                    'name' => $tenant->name,
                    'metadata' => [
                        'tenant_public_id' => $tenant->public_id,
                    ],
                ]);
        } finally {
            Cache::forget($cacheKey);
        }

        return (string) $checkout->url;
    }

    /**
     * Resuelve el price ID del plan actual desde Stripe.
     */
    private function resolveCurrentPlan(mixed $subscription): ?string
    {
        $currentBasePrices = [
            $this->priceFor(PlanCode::Business),
            $this->priceFor(PlanCode::Restaurant),
        ];

        $baseItem = $subscription->items()
            ->whereIn('stripe_price', $currentBasePrices)
            ->first();

        return $baseItem?->stripe_price;
    }

    /**
     * Resuelve el PlanCode del plan actual desde el price ID.
     */
    private function resolveCurrentPlanCode(mixed $subscription): ?PlanCode
    {
        $priceId = $this->resolveCurrentPlan($subscription);

        if ($priceId === null) {
            return null;
        }

        foreach ([PlanCode::Business, PlanCode::Restaurant] as $plan) {
            if ($this->priceFor($plan) === $priceId) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * Obtiene el monto de un price ID desde la API de Stripe (con caché en memoria).
     */
    private function getPriceAmount(string $priceId): int
    {
        static $cache = [];

        if (! isset($cache[$priceId])) {
            $price = $this->stripe->prices->retrieve($priceId);
            $cache[$priceId] = (int) ($price->unit_amount ?? 0);
        }

        return $cache[$priceId];
    }

    private function priceFor(PlanCode $plan): string
    {
        if ($plan === PlanCode::Free) {
            throw new DomainException('El plan gratuito no requiere Stripe.');
        }

        return $this->configuredValue('billing.plans.' . $plan->value . '.price');
    }

    private function configuredValue(string $key): string
    {
        $value = config($key);

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Falta configurar {$key}.");
        }

        return $value;
    }

    public function listInvoices(
        Tenant $tenant,
        int $limit = 5,
        ?string $startingAfter = null,
    ): array {
        $customerId = trim(
            (string) $tenant->stripe_id
        );

        /*
     * Si el tenant todavía no tiene customer en Stripe,
     * simplemente regresamos un historial vacío.
     */
        if ($customerId === '') {
            return [
                'data' => [],

                'pagination' => [
                    'limit' => $limit,
                    'has_more' => false,
                    'next_cursor' => null,
                ],
            ];
        }

        /*
     * Protección defensiva aunque el controlador
     * ya valide el límite.
     */
        $limit = max(
            1,
            min($limit, 20)
        );

        /*
     * Si recibimos cursor, comprobamos que esa factura
     * realmente pertenezca al customer del tenant actual.
     */
        if ($startingAfter !== null) {
            $startingAfter = trim($startingAfter);

            if ($startingAfter === '') {
                $startingAfter = null;
            } else {
                $this->assertInvoiceBelongsToCustomer(
                    invoiceId: $startingAfter,
                    customerId: $customerId,
                    tenantId: (string) $tenant->getKey(),
                );
            }
        }

        $parameters = [
            /*
         * Esta es la protección principal contra IDOR:
         * nunca recibimos customer_id desde el frontend.
         */
            'customer' => $customerId,

            'limit' => $limit,
        ];

        if ($startingAfter !== null) {
            $parameters['starting_after'] =
                $startingAfter;
        }

        try {
            $stripeInvoices = $this->stripe
                ->invoices
                ->all($parameters);
        } catch (
            \Stripe\Exception\InvalidRequestException $exception
        ) {
            Log::warning(
                'Cursor inválido al consultar facturas de Stripe.',
                [
                    'tenant_id' =>
                    $tenant->getKey(),

                    'stripe_customer_id' =>
                    $customerId,

                    'cursor' =>
                    $startingAfter,

                    'limit' =>
                    $limit,

                    'message' =>
                    $exception->getMessage(),

                    'stripe_code' =>
                    $exception->getStripeCode(),
                ],
            );

            throw new DomainException(
                'El cursor de facturación no es válido.'
            );
        } catch (
            \Stripe\Exception\ApiErrorException $exception
        ) {
            Log::error(
                'No se pudieron consultar las facturas de Stripe.',
                [
                    'tenant_id' =>
                    $tenant->getKey(),

                    'stripe_customer_id' =>
                    $customerId,

                    'cursor' =>
                    $startingAfter,

                    'limit' =>
                    $limit,

                    'message' =>
                    $exception->getMessage(),

                    'stripe_code' =>
                    $exception->getStripeCode(),
                ],
            );

            throw new DomainException(
                'No pudimos consultar las facturas. Intenta de nuevo.'
            );
        }

        $mappedInvoices = array_map(
            fn(mixed $invoice): array =>
            $this->mapInvoice($invoice),
            $stripeInvoices->data,
        );

        /*
     * Stripe regresa primero las facturas más recientes.
     * El cursor siguiente es el ID de la última factura
     * de esta página.
     */
        $lastInvoice = ! empty($stripeInvoices->data)
            ? $stripeInvoices->data[array_key_last(
                $stripeInvoices->data
            )]
            : null;

        $hasMore = (bool) (
            $stripeInvoices->has_more
            ?? false
        );

        return [
            'data' => $mappedInvoices,

            'pagination' => [
                'limit' => $limit,

                'has_more' => $hasMore,

                'next_cursor' => (
                    $hasMore
                    && $lastInvoice !== null
                )
                    ? (string) $lastInvoice->id
                    : null,
            ],
        ];
    }

    private function assertInvoiceBelongsToCustomer(
        string $invoiceId,
        string $customerId,
        string $tenantId,
    ): void {
        try {
            $invoice = $this->stripe
                ->invoices
                ->retrieve(
                    $invoiceId,
                    []
                );
        } catch (
            \Stripe\Exception\ApiErrorException $exception
        ) {
            Log::warning(
                'Se intentó usar un cursor de factura inválido.',
                [
                    'tenant_id' =>
                    $tenantId,

                    'stripe_customer_id' =>
                    $customerId,

                    'cursor' =>
                    $invoiceId,

                    'message' =>
                    $exception->getMessage(),

                    'stripe_code' =>
                    $exception->getStripeCode(),
                ],
            );

            /*
         * No revelamos si la factura existe.
         */
            throw new DomainException(
                'El cursor de facturación no es válido.'
            );
        }

        /*
     * customer puede venir como ID o como objeto
     * si estuviera expandido.
     */
        $invoiceCustomerId =
            data_get($invoice, 'customer.id')
            ?? data_get($invoice, 'customer');

        if (
            ! is_string($invoiceCustomerId)
            || $invoiceCustomerId === ''
            || ! hash_equals(
                $customerId,
                $invoiceCustomerId
            )
        ) {
            Log::warning(
                'Se intentó usar una factura de otro customer como cursor.',
                [
                    'tenant_id' =>
                    $tenantId,

                    'stripe_customer_id' =>
                    $customerId,

                    'cursor' =>
                    $invoiceId,
                ],
            );

            throw new DomainException(
                'El cursor de facturación no es válido.'
            );
        }
    }

    private function mapInvoice(
        mixed $invoice,
    ): array {
        $totalInCents = (int) (
            $invoice->total
            ?? 0
        );

        $amountDueInCents = (int) (
            $invoice->amount_due
            ?? 0
        );

        $amountPaidInCents = (int) (
            $invoice->amount_paid
            ?? 0
        );

        $amountRemainingInCents = (int) (
            $invoice->amount_remaining
            ?? 0
        );

        $status = (string) (
            $invoice->status
            ?? 'unknown'
        );

        return [
            'id' => (string) $invoice->id,

            'number' =>
            $invoice->number
                ?? null,

            'date' => isset($invoice->created)
                ? \Carbon\CarbonImmutable::createFromTimestamp(
                    (int) $invoice->created
                )->toIso8601String()
                : null,

            'plan_name' =>
            $this->resolveInvoicePlanName(
                $invoice
            ),

            'total' =>
            $this->fromStripeAmount(
                $totalInCents
            ),

            'amount_due' =>
            $this->fromStripeAmount(
                $amountDueInCents
            ),

            'amount_paid' =>
            $this->fromStripeAmount(
                $amountPaidInCents
            ),

            'amount_remaining' =>
            $this->fromStripeAmount(
                $amountRemainingInCents
            ),

            'currency' => strtoupper(
                (string) (
                    $invoice->currency
                    ?? 'mxn'
                )
            ),

            /*
         * Estado original de Stripe.
         */
            'status' => $status,

            /*
         * Estado amigable para el frontend.
         */
            'status_label' =>
            $this->invoiceStatusLabel(
                status: $status,
                totalInCents: $totalInCents,
                amountDueInCents: $amountDueInCents,
                amountPaidInCents: $amountPaidInCents,
            ),

            'billing_reason' =>
            $invoice->billing_reason
                ?? null,

            'url' =>
            $invoice->hosted_invoice_url
                ?? null,

            'pdf' =>
            $invoice->invoice_pdf
                ?? null,
        ];
    }

    private function fromStripeAmount(
        int $amountInCents,
    ): float {
        return round(
            $amountInCents / 100,
            2
        );
    }

    private function invoiceStatusLabel(
        string $status,
        int $totalInCents,
        int $amountDueInCents,
        int $amountPaidInCents,
    ): string {
        /*
     * Ejemplo:
     * una factura borrador con importe negativo
     * normalmente representa un crédito o ajuste.
     */
        if (
            $status === 'draft'
            && $totalInCents < 0
        ) {
            return 'Crédito pendiente';
        }

        /*
     * La factura fue completada sin cobrar dinero.
     * Puede haber sido cubierta con saldo o crédito.
     */
        if (
            $status === 'paid'
            && $amountDueInCents === 0
            && $amountPaidInCents === 0
        ) {
            return 'Sin cargo';
        }

        return match ($status) {
            'draft' => 'Borrador',
            'open' => 'Pendiente de pago',
            'paid' => 'Pagada',
            'void' => 'Anulada',
            'uncollectible' => 'Incobrable',
            default => 'Desconocido',
        };
    }

    private function resolveInvoicePlanName(
        mixed $invoice,
    ): string {
        /*
     * Primero intentamos obtenerlo desde metadata.
     */
        $invoicePlanName = data_get(
            $invoice,
            'metadata.plan_name'
        );

        if (
            is_string($invoicePlanName)
            && $invoicePlanName !== ''
        ) {
            return $invoicePlanName;
        }

        /*
     * Después revisamos metadata de la primera línea.
     */
        $linePlanName = data_get(
            $invoice,
            'lines.data.0.metadata.plan_name'
        );

        if (
            is_string($linePlanName)
            && $linePlanName !== ''
        ) {
            return $linePlanName;
        }

        /*
     * Intentamos obtener el nickname del Price.
     */
        $priceNickname = data_get(
            $invoice,
            'lines.data.0.price.nickname'
        );

        if (
            is_string($priceNickname)
            && $priceNickname !== ''
        ) {
            return $priceNickname;
        }

        /*
     * Finalmente usamos la descripción de la línea.
     */
        $description = data_get(
            $invoice,
            'lines.data.0.description'
        );

        if (
            is_string($description)
            && $description !== ''
        ) {
            return $description;
        }

        return '—';
    }
}
