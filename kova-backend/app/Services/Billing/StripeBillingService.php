<?php

namespace App\Services\Billing;

use App\Contracts\Billing\StripeBillingGateway;
use App\Data\Billing\StripeBillingSnapshot;
use App\Data\Billing\StripeSubscriptionData;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Stripe\StripeClient;
use Throwable;

final class StripeBillingService implements StripeBillingGateway
{
    public function __construct(
        private readonly StripeClient $stripe,
    ) {}

    public function getSnapshot(Tenant $tenant): StripeBillingSnapshot
    {
        if (! $tenant->hasStripeId()) {
            return StripeBillingSnapshot::withoutCustomer();
        }

        [$subscriptionLookupSucceeded, $currentSubscription, $displaySubscription] = $this->retrieveSubscriptions(
            $tenant->stripe_id
        );

        $customer = $this->retrieveCustomer($tenant->stripe_id);

        return new StripeBillingSnapshot(
            customerExists: true,
            subscriptionLookupSucceeded: $subscriptionLookupSucceeded,
            currentSubscription: $currentSubscription,
            displaySubscription: $displaySubscription,
            paymentMethod: $this->resolvePaymentMethod(
                customerId: $tenant->stripe_id,
                customer: $customer,
            ),
            billingDetails: $this->mapBillingDetails($customer),
        );
    }

    /**
     * @return array{0: bool, 1: StripeSubscriptionData|null, 2: StripeSubscriptionData|null}
     */
    private function retrieveSubscriptions(string $customerId): array
    {
        try {
            $response = $this->stripe->subscriptions->all([
                'customer' => $customerId,
                'status' => 'all',
                'limit' => 100,
            ]);

            $subscriptions = collect($response->data)
                ->map(fn(object $subscription): StripeSubscriptionData => $this->mapSubscription($subscription))
                ->sortByDesc('createdAt')
                ->values();

            $currentSubscription = $this->findCurrentSubscription($subscriptions);
            $displaySubscription = $currentSubscription ?? $subscriptions->first();

            return [true, $currentSubscription, $displaySubscription];
        } catch (Throwable $exception) {
            report($exception);

            return [false, null, null];
        }
    }

    /**
     * @param Collection<int, StripeSubscriptionData> $subscriptions
     */
    private function findCurrentSubscription(Collection $subscriptions): ?StripeSubscriptionData
    {
        foreach (['active', 'trialing', 'past_due'] as $status) {
            $subscription = $subscriptions->first(
                fn(StripeSubscriptionData $subscription): bool => $subscription->status === $status
            );

            if ($subscription) {
                return $subscription;
            }
        }

        return null;
    }

    private function mapSubscription(object $subscription): StripeSubscriptionData
    {
        $items = collect(data_get($subscription, 'items.data', []));

        $priceIds = $items
            ->map(fn(object $item): ?string => data_get($item, 'price.id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $periodEndTimestamp = data_get($subscription, 'current_period_end')
            ?? $items->map(fn(object $item): ?int => data_get($item, 'current_period_end'))->filter()->max();

        $endedAtTimestamp = data_get($subscription, 'ended_at');

        return new StripeSubscriptionData(
            id: (string) data_get($subscription, 'id'),
            status: (string) data_get($subscription, 'status'),
            priceIds: $priceIds,
            cancelAtPeriodEnd: (bool) data_get($subscription, 'cancel_at_period_end', false),
            currentPeriodEndsAt: $periodEndTimestamp
                ? CarbonImmutable::createFromTimestampUTC((int) $periodEndTimestamp)
                : null,
            endedAt: $endedAtTimestamp
                ? CarbonImmutable::createFromTimestampUTC((int) $endedAtTimestamp)
                : null,
            createdAt: (int) data_get($subscription, 'created', 0),
        );
    }

    private function retrieveCustomer(string $customerId): ?object
    {
        try {
            return $this->stripe->customers->retrieve(
                $customerId,
                ['expand' => ['default_source', 'invoice_settings.default_payment_method']],
            );
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePaymentMethod(
        string $customerId,
        ?object $customer,
    ): ?array {
        /*
     * Como invoice_settings.default_payment_method
     * ya viene expandido desde retrieveCustomer(),
     * idealmente esto ya debería ser un objeto.
     */
        $paymentMethod = $customer
            ? data_get(
                $customer,
                'invoice_settings.default_payment_method'
            )
            : null;

        $paymentMethod ??= $customer
            ? data_get(
                $customer,
                'default_source'
            )
            : null;

        /*
     * Si por alguna razón Stripe regresó solamente
     * un pm_ en vez del objeto expandido.
     */
        if (
            is_string($paymentMethod)
            && str_starts_with(
                $paymentMethod,
                'pm_'
            )
        ) {


            try {
                $paymentMethod = $this->stripe
                    ->paymentMethods
                    ->retrieve(
                        $paymentMethod
                    );


            } catch (Throwable $exception) {
                report($exception);

                $paymentMethod = null;
            }
        }

        /*
     * Si el customer no tiene default payment method,
     * buscamos la primera tarjeta.
     *
     * ESTA ES OTRA PETICIÓN HTTP A STRIPE.
     */
        if (! is_object($paymentMethod)) {


            try {
                $response = $this->stripe
                    ->paymentMethods
                    ->all([
                        'customer' => $customerId,
                        'type' => 'card',
                        'limit' => 1,
                    ]);

                

                $paymentMethod =
                    $response->data[0]
                    ?? null;
            } catch (Throwable $exception) {
                report($exception);

                $paymentMethod = null;
            }
        }

        if (! is_object($paymentMethod)) {
            return null;
        }

        return [
            'brand' =>
            data_get(
                $paymentMethod,
                'card.brand'
            )
                ?? data_get(
                    $paymentMethod,
                    'brand'
                ),

            'last4' =>
            data_get(
                $paymentMethod,
                'card.last4'
            )
                ?? data_get(
                    $paymentMethod,
                    'last4'
                ),

            'exp_month' =>
            data_get(
                $paymentMethod,
                'card.exp_month'
            )
                ?? data_get(
                    $paymentMethod,
                    'exp_month'
                ),

            'exp_year' =>
            data_get(
                $paymentMethod,
                'card.exp_year'
            )
                ?? data_get(
                    $paymentMethod,
                    'exp_year'
                ),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapBillingDetails(?object $customer): ?array
    {
        if (! $customer) {
            return null;
        }

        $address = data_get($customer, 'address');

        return [
            'name' => data_get($customer, 'name'),
            'email' => data_get($customer, 'email'),
            'phone' => data_get($customer, 'phone'),
            'address' => $address ? [
                'line1' => data_get($address, 'line1'),
                'line2' => data_get($address, 'line2'),
                'city' => data_get($address, 'city'),
                'state' => data_get($address, 'state'),
                'postal_code' => data_get($address, 'postal_code'),
                'country' => data_get($address, 'country'),
            ] : null,
        ];
    }

}
