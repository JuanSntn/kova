<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\StripeBillingGateway;
use App\Enums\SubscriptionStatus;
use App\Listeners\SyncStripePlanSubscription;
use App\Models\AddonPrice;
use App\Models\BillingOutbox;
use App\Models\BillingPriceMapping;
use App\Models\BillingSubscriptionLink;
use App\Models\PlanPrice;
use App\Models\PlanSubscription;
use App\Models\SubscriptionChange;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Subscriptions\BillingProcessor;
use App\Services\Subscriptions\BillingReconciler;
use App\Services\Subscriptions\CatalogService;
use App\Services\Subscriptions\ContractService;
use App\Services\Subscriptions\EntitlementService;
use App\Tenancy\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;
use Stripe\ApiRequestor;
use Stripe\BaseStripeClient;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;
use Stripe\Util\ApiVersion;
use Tests\TestCase;

class BillingProcessorTest extends TestCase
{
    use RefreshDatabase;

    private StripeTestHttp $http;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->http = new StripeTestHttp;
        ApiRequestor::setHttpClient($this->http);
        $this->app->instance(StripeClient::class, new StripeClient('sk_test_fake'));
        config(['billing.tax_rate' => 'txr_test']);
        $this->tenant = Tenant::create(['name' => 'Remote', 'slug' => 'remote', 'status' => 'active']);
        app(CurrentTenant::class)->set($this->tenant);
        foreach (PlanPrice::all() as $p) {
            BillingPriceMapping::create(['plan_price_id' => $p->id, 'stripe_price_id' => 'price_'.$p->public_id]);
        }
        foreach (AddonPrice::all() as $p) {
            BillingPriceMapping::create(['addon_price_id' => $p->id, 'stripe_price_id' => 'price_'.$p->public_id]);
        }
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(new CurlClient);
        parent::tearDown();
    }

    public function test_checkout_retry_uses_same_operation_and_never_duplicates_customer_or_session(): void
    {
        $catalog = app(CatalogService::class);
        $contracts = app(ContractService::class);
        $s = $contracts->create($this->tenant, $catalog->price('business', 'year'));
        $op = $contracts->enqueue($this->tenant, 'checkout', 'test', ['contract_id' => $s->id, 'email' => 'test@example.test']);
        $this->http->responses = [['id' => 'cus_remote', 'object' => 'customer'], ['id' => 'cs_remote', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/test']];
        $a = app(BillingProcessor::class)->run($op);
        $b = app(BillingProcessor::class)->run($op);
        $this->assertSame($a, $b);
        $this->assertCount(2, $this->http->requests);
        $params = $this->http->requests[1]['params'];
        $this->assertSame($catalog->mapping($s->price)->stripe_price_id, $params['line_items'][0]['price']);
        $this->assertSame($s->public_id, $params['subscription_data']['metadata']['contract_public_id']);
        $this->assertSame(['txr_test'], $params['subscription_data']['default_tax_rates']);
        $this->assertSame('completed', $op->refresh()->status);
    }

    public function test_continuing_pending_checkout_with_a_new_request_key_reuses_session_without_activation(): void
    {
        $gateway = app(StripeBillingGateway::class);
        $user = User::factory()->create();
        $this->http->responses = [
            ['id' => 'cus_remote', 'object' => 'customer'],
            ['id' => 'cs_remote', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/test'],
        ];
        $first = $gateway->createCheckout($this->tenant, $user, 'business', 'first-request');
        $contract = $this->tenant->planSubscriptions()->firstOrFail();
        $this->http->responses = [
            $this->checkoutSession('cs_remote', 'open', 'unpaid', $contract),
        ];
        $second = $gateway->createCheckout($this->tenant->refresh(), $user, 'business', 'resume-request');
        $this->assertSame($first, $second);
        $this->assertCount(3, $this->http->requests);
        $this->assertSame(1, BillingOutbox::where('kind', 'checkout')->count());
        $this->assertSame(1, $this->tenant->planSubscriptions()->count());
        $contract = $this->tenant->planSubscriptions()->firstOrFail();
        $this->assertSame(SubscriptionStatus::Pending, $contract->status);
        $this->assertNull($contract->current_tenant_id);
    }

    public function test_cancel_pending_checkout_expires_open_unpaid_session_without_touching_free(): void
    {
        $catalog = app(CatalogService::class);
        $contracts = app(ContractService::class);
        $free = $contracts->create($this->tenant, $catalog->price('free'), true);
        $this->tenant->forceFill(['stripe_id' => 'cus_remote'])->save();
        $pending = $contracts->create($this->tenant, $catalog->price('business'));
        $op = $contracts->enqueue($this->tenant, 'checkout', 'abandoned', ['contract_id' => $pending->id, 'email' => 'test@example.test']);
        $op->update(['status' => 'completed', 'result' => ['url' => 'https://checkout.stripe.test/old', 'session_id' => 'cs_old']]);
        $this->http->responses = [
            $this->checkoutSession('cs_old', 'open', 'unpaid', $pending),
            $this->checkoutSession('cs_old', 'expired', 'unpaid', $pending),
        ];

        $result = app(BillingReconciler::class)->cancelPendingCheckout($this->tenant->refresh());

        $this->assertSame('cancelled', $result['state']);
        $this->assertSame(SubscriptionStatus::Active, $free->refresh()->status);
        $this->assertSame($this->tenant->id, $free->current_tenant_id);
        $this->assertNotNull($pending->refresh()->ended_at);
        $this->assertTrue(str_ends_with($this->http->requests[1]['absUrl'], '/expire'));
    }

    public function test_selecting_another_offer_expires_abandoned_checkout_and_creates_a_new_attempt(): void
    {
        $gateway = app(StripeBillingGateway::class);
        $user = User::factory()->create();
        $this->http->responses = [
            ['id' => 'cus_remote', 'object' => 'customer'],
            ['id' => 'cs_business', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/business'],
        ];

        $first = $gateway->createCheckout($this->tenant, $user, 'business', 'first-request');
        $business = $this->tenant->planSubscriptions()->firstOrFail();
        $this->http->responses = [
            $this->checkoutSession('cs_business', 'open', 'unpaid', $business),
            $this->checkoutSession('cs_business', 'expired', 'unpaid', $business),
            ['id' => 'cs_restaurant', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/restaurant'],
        ];
        $second = $gateway->createCheckout($this->tenant->refresh(), $user, 'restaurant', 'second-request');

        $this->assertSame('https://checkout.stripe.test/business', $first);
        $this->assertSame('https://checkout.stripe.test/restaurant', $second);
        $this->assertSame(2, $this->tenant->planSubscriptions()->count());
        $this->assertNotNull($this->tenant->planSubscriptions()->oldest('id')->first()->ended_at);
        $this->assertNull($this->tenant->planSubscriptions()->latest('id')->first()->ended_at);
        $this->assertCount(5, $this->http->requests);
    }

    public function test_complete_unpaid_checkout_does_not_activate_the_paid_contract(): void
    {
        $catalog = app(CatalogService::class);
        $contracts = app(ContractService::class);
        $contracts->create($this->tenant, $catalog->price('free'), true);
        $this->tenant->forceFill(['stripe_id' => 'cus_remote'])->save();
        $pending = $contracts->create($this->tenant, $catalog->price('business'));
        $op = $contracts->enqueue($this->tenant, 'checkout', 'async-payment', ['contract_id' => $pending->id, 'email' => 'test@example.test']);
        $op->update(['status' => 'completed', 'result' => ['url' => 'https://checkout.stripe.test/async', 'session_id' => 'cs_async']]);
        $this->http->responses = [
            $this->checkoutSession('cs_async', 'complete', 'unpaid', $pending),
        ];

        app(BillingReconciler::class)->checkout($op);

        $this->assertNull($pending->refresh()->current_tenant_id);
        $this->assertSame('free', app(EntitlementService::class)->current($this->tenant)->version->plan->code);
        $this->assertSame('awaiting_payment', $op->refresh()->result['state']);
    }

    public function test_reactivation_requires_cancelling_scheduled_change_then_updates_both_components(): void
    {
        $catalog = app(CatalogService::class);
        $contracts = app(ContractService::class);
        $subscription = $contracts->create(
            $this->tenant,
            $catalog->price('business'),
        );

        foreach (['base', 'extras'] as $role) {
            BillingSubscriptionLink::query()->create([
                'plan_subscription_id' => $subscription->id,
                'role' => $role,
                'stripe_subscription_id' => 'sub_'.$role.'_reactivate',
                'status' => 'active',
                'period_starts_at' => now(),
                'period_ends_at' => now()->addMonth(),
            ]);
        }

        $contracts->activate($subscription);
        $subscription->refresh()->update([
            'cancel_at_period_end' => true,
            'cancellation_requested_at' => now(),
        ]);
        $change = SubscriptionChange::query()->create([
            'plan_subscription_id' => $subscription->id,
            'kind' => 'plan',
            'change_mode' => 'period_end',
            'status' => 'pending',
            'target_plan_price_id' => $catalog->price('restaurant')->id,
            'effective_at' => now()->addMonth(),
        ]);
        $operation = $contracts->enqueue(
            $this->tenant,
            'reactivate',
            'reactivate-test',
            [
                'contract_id' => $subscription->id,
                'retained_branch_ids' => [],
            ],
        );

        try {
            app(BillingProcessor::class)->run($operation);
            $this->fail('La reactivación no debía ignorar el cambio programado.');
        } catch (\DomainException $exception) {
            $this->assertSame(
                'Cancela el cambio programado antes de reactivar la suscripción.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue(
            $subscription->refresh()->cancel_at_period_end
        );
        $this->assertCount(0, $this->http->requests);

        $change->update([
            'status' => 'cancelled',
            'completed_at' => now(),
        ]);
        $this->http->responses = [
            ['id' => 'sub_base_reactivate', 'object' => 'subscription'],
            ['id' => 'sub_extras_reactivate', 'object' => 'subscription'],
        ];

        $result = app(BillingProcessor::class)->run(
            $operation->refresh()
        );

        $this->assertFalse($result['cancel_at_period_end']);
        $this->assertFalse(
            $subscription->refresh()->cancel_at_period_end
        );
        $this->assertNull($subscription->cancellation_requested_at);
        $this->assertSame('completed', $operation->refresh()->status);
        $this->assertCount(2, $this->http->requests);
        $this->assertSame(
            'false',
            $this->http->requests[0]['params']['cancel_at_period_end'],
        );
        $this->assertSame(
            '',
            $this->http->requests[1]['params']['cancel_at'],
        );
    }

    public function test_gateway_rejects_reactivation_before_creating_an_outbox_operation(): void
    {
        $catalog = app(CatalogService::class);
        $contracts = app(ContractService::class);
        $subscription = $contracts->create(
            $this->tenant,
            $catalog->price('business'),
        );

        foreach (['base', 'extras'] as $role) {
            BillingSubscriptionLink::query()->create([
                'plan_subscription_id' => $subscription->id,
                'role' => $role,
                'stripe_subscription_id' => 'sub_'.$role.'_gateway',
                'status' => 'active',
                'period_starts_at' => now(),
                'period_ends_at' => now()->addMonth(),
            ]);
        }

        $contracts->activate($subscription);
        $subscription->refresh()->update([
            'cancel_at_period_end' => true,
            'cancellation_requested_at' => now(),
        ]);

        SubscriptionChange::query()->create([
            'plan_subscription_id' => $subscription->id,
            'kind' => 'plan',
            'change_mode' => 'period_end',
            'status' => 'pending',
            'target_plan_price_id' => $catalog->price('restaurant')->id,
            'effective_at' => now()->addMonth(),
        ]);

        try {
            app(StripeBillingGateway::class)
                ->reactivateSubscription($this->tenant);
            $this->fail('La reactivación no debía encolarse.');
        } catch (\DomainException $exception) {
            $this->assertSame(
                'Cancela el cambio programado antes de reactivar la suscripción.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(0, BillingOutbox::query()->count());
        $this->assertTrue(
            $subscription->refresh()->cancel_at_period_end
        );
        $this->assertCount(0, $this->http->requests);
    }

    public function test_cancelling_scheduled_change_allows_reactivation_of_base_and_extras(): void
    {
        $catalog = app(CatalogService::class);
        $contracts = app(ContractService::class);
        $subscription = $contracts->create(
            $this->tenant,
            $catalog->price('business'),
        );

        foreach (['base', 'extras'] as $role) {
            BillingSubscriptionLink::query()->create([
                'plan_subscription_id' => $subscription->id,
                'role' => $role,
                'stripe_subscription_id' => 'sub_'.$role.'_flow',
                'status' => 'active',
                'period_starts_at' => now(),
                'period_ends_at' => now()->addMonth(),
            ]);
        }

        $contracts->activate($subscription);
        $subscription->refresh()->update([
            'cancel_at_period_end' => true,
            'cancellation_requested_at' => now(),
        ]);
        $change = SubscriptionChange::query()->create([
            'plan_subscription_id' => $subscription->id,
            'kind' => 'plan',
            'change_mode' => 'period_end',
            'status' => 'pending',
            'target_plan_price_id' => $catalog->price('restaurant')->id,
            'effective_at' => now()->addMonth(),
        ]);
        $schedule = $contracts->enqueue(
            $this->tenant,
            'schedule_plan',
            'scheduled-change-flow',
            ['change_id' => $change->id],
        );
        $schedule->update([
            'status' => 'completed',
            'result' => [
                'schedule_id' => 'sub_sched_flow',
                'extras_schedule_id' => 'sub_sched_extras_flow',
            ],
        ]);

        $cancelOperation = $contracts->cancelChange($change);
        $this->http->responses = [
            [
                'id' => 'sub_sched_flow',
                'object' => 'subscription_schedule',
                'status' => 'released',
            ],
            [
                'id' => 'sub_sched_extras_flow',
                'object' => 'subscription_schedule',
                'status' => 'released',
            ],
        ];
        app(BillingProcessor::class)->run($cancelOperation);

        $this->assertSame('cancelled', $change->refresh()->status);
        $this->assertTrue(
            $subscription->refresh()->cancel_at_period_end
        );

        $this->http->responses = [
            ['id' => 'sub_base_flow', 'object' => 'subscription'],
            ['id' => 'sub_extras_flow', 'object' => 'subscription'],
        ];
        app(StripeBillingGateway::class)
            ->reactivateSubscription($this->tenant);

        $this->assertFalse(
            $subscription->refresh()->cancel_at_period_end
        );
        $this->assertNull($subscription->cancellation_requested_at);
        $this->assertSame(
            1,
            BillingOutbox::query()
                ->where('kind', 'reactivate')
                ->where('status', 'completed')
                ->count(),
        );
        $this->assertCount(4, $this->http->requests);
    }

    public function test_partial_reactivation_failure_does_not_update_local_contract_and_reuses_operation(): void
    {
        $catalog = app(CatalogService::class);
        $contracts = app(ContractService::class);
        $subscription = $contracts->create(
            $this->tenant,
            $catalog->price('business'),
        );

        foreach (['base', 'extras'] as $role) {
            BillingSubscriptionLink::query()->create([
                'plan_subscription_id' => $subscription->id,
                'role' => $role,
                'stripe_subscription_id' => 'sub_'.$role.'_partial',
                'status' => 'active',
                'period_starts_at' => now(),
                'period_ends_at' => now()->addMonth(),
            ]);
        }

        $contracts->activate($subscription);
        $subscription->refresh()->update([
            'cancel_at_period_end' => true,
            'cancellation_requested_at' => now(),
        ]);
        $operation = $contracts->enqueue(
            $this->tenant,
            'reactivate',
            'partial-reactivation',
            [
                'contract_id' => $subscription->id,
                'retained_branch_ids' => [],
            ],
        );
        $this->http->responses = [
            ['id' => 'sub_base_partial', 'object' => 'subscription'],
            new \RuntimeException('Stripe extras unavailable'),
        ];

        try {
            app(BillingProcessor::class)->run($operation);
            $this->fail('El fallo parcial debía propagarse.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Stripe extras unavailable',
                $exception->getMessage(),
            );
        }

        $this->assertTrue(
            $subscription->refresh()->cancel_at_period_end
        );
        $this->assertSame('pending', $operation->refresh()->status);

        $firstBaseHeaders = $this->http->requests[0]['headers'];
        $firstExtrasHeaders = $this->http->requests[1]['headers'];
        $this->http->responses = [
            ['id' => 'sub_base_partial', 'object' => 'subscription'],
            ['id' => 'sub_extras_partial', 'object' => 'subscription'],
        ];
        app(BillingProcessor::class)->run($operation->refresh());

        $this->assertFalse(
            $subscription->refresh()->cancel_at_period_end
        );
        $this->assertSame('completed', $operation->refresh()->status);
        $this->assertSame(
            $this->idempotency($firstBaseHeaders),
            $this->idempotency(
                $this->http->requests[2]['headers']
            ),
        );
        $this->assertSame(
            $this->idempotency($firstExtrasHeaders),
            $this->idempotency(
                $this->http->requests[3]['headers']
            ),
        );
    }

    public function test_network_failure_keeps_operation_retryable_with_same_idempotency_key(): void
    {
        $s = app(ContractService::class)->create($this->tenant, app(CatalogService::class)->price('business'));
        $op = app(ContractService::class)->enqueue($this->tenant, 'checkout', 'retry', ['contract_id' => $s->id, 'email' => 'test@example.test']);
        $this->http->responses = [new \RuntimeException('Connection interrupted')];
        try {
            app(BillingProcessor::class)->run($op);
        } catch (\RuntimeException) {
        }
        $this->assertSame('pending', $op->refresh()->status);
        $first = $this->http->requests[0]['headers'];
        $this->http->responses = [['id' => 'cus_remote', 'object' => 'customer'], ['id' => 'cs_remote', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/test']];
        app(BillingProcessor::class)->run($op);
        $this->assertSame($this->idempotency($first), $this->idempotency($this->http->requests[1]['headers']));
    }

    public function test_reusing_operation_key_with_different_payload_is_rejected(): void
    {
        $service = app(ContractService::class);
        $service->enqueue($this->tenant, 'meter', 'same', ['quantity' => 1]);
        $this->expectException(\DomainException::class);
        $service->enqueue($this->tenant, 'meter', 'same', ['quantity' => 2]);
    }

    public function test_simultaneous_worker_cannot_claim_processing_operation(): void
    {
        $op = app(ContractService::class)->enqueue($this->tenant, 'meter', 'locked', ['quantity' => 1]);
        $op->update(['locked_at' => now(), 'status' => 'processing']);
        $this->expectException(\DomainException::class);
        app(BillingProcessor::class)->run($op);
    }

    public function test_pending_contract_slot_prevents_a_second_pending_contract(): void
    {
        $contracts = app(ContractService::class);
        $catalog = app(CatalogService::class);
        $first = $contracts->create(
            $this->tenant,
            $catalog->price('business'),
        );

        $this->assertSame(
            $this->tenant->id,
            $first->pending_tenant_id,
        );

        $this->expectException(QueryException::class);
        $contracts->create(
            $this->tenant,
            $catalog->price('restaurant'),
        );
    }

    public function test_never_started_checkout_is_cancelled_atomically_and_never_processed(): void
    {
        $contracts = app(ContractService::class);
        $pending = $contracts->create(
            $this->tenant,
            app(CatalogService::class)->price('business'),
        );
        $operation = $contracts->enqueue(
            $this->tenant,
            'checkout',
            'never-started',
            [
                'contract_id' => $pending->id,
                'email' => 'test@example.test',
            ],
        );

        $result = app(BillingReconciler::class)
            ->cancelPendingCheckout($this->tenant);

        $this->assertSame('cancelled', $result['state']);
        $this->assertSame('cancelled', $operation->refresh()->status);
        $this->assertNull($pending->refresh()->pending_tenant_id);
        $this->assertNotNull($pending->ended_at);
        $this->assertSame([], app(BillingProcessor::class)->run($operation));
        $this->assertCount(0, $this->http->requests);
    }

    public function test_processing_checkout_cannot_be_cancelled_without_remote_state(): void
    {
        $contracts = app(ContractService::class);
        $pending = $contracts->create(
            $this->tenant,
            app(CatalogService::class)->price('business'),
        );
        $operation = $contracts->enqueue(
            $this->tenant,
            'checkout',
            'processing-checkout',
            [
                'contract_id' => $pending->id,
                'email' => 'test@example.test',
            ],
        );
        $operation->update([
            'status' => 'processing',
            'locked_at' => now(),
            'lock_token' => '00000000-0000-4000-8000-000000000001',
            'attempts' => 1,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('todavía se está preparando');
        app(BillingReconciler::class)
            ->cancelPendingCheckout($this->tenant);
    }

    public function test_worker_that_lost_its_lease_cannot_finalize_operation(): void
    {
        $operation = app(ContractService::class)->enqueue(
            $this->tenant,
            'meter',
            'lost-lease',
            ['quantity' => 1],
        );
        $operation->update([
            'status' => 'processing',
            'locked_at' => now(),
            'lock_token' => '00000000-0000-4000-8000-000000000002',
        ]);

        $method = new \ReflectionMethod(
            BillingProcessor::class,
            'completeLease',
        );

        $completed = $method->invoke(
            app(BillingProcessor::class),
            $operation->id,
            '00000000-0000-4000-8000-000000000001',
            ['status' => 'confirmed'],
        );

        $this->assertFalse($completed);
        $this->assertSame('processing', $operation->refresh()->status);
        $this->assertNull($operation->result);
    }

    public function test_cashier_stripe_client_binding_preserves_requested_configuration(): void
    {
        $this->app->forgetInstance(StripeClient::class);
        config(['cashier.secret' => 'sk_test_binding']);

        $client = Cashier::stripe([
            'api_base' => 'https://stripe.invalid',
        ]);
        $property = new \ReflectionProperty(
            BaseStripeClient::class,
            'config',
        );
        $configuration = $property->getValue($client);

        $this->assertSame('sk_test_binding', $client->getApiKey());
        $this->assertSame('https://stripe.invalid', $client->getApiBase());
        $this->assertSame(
            ApiVersion::CURRENT,
            $configuration['stripe_version'],
        );
    }

    public function test_webhook_refetches_current_state_once_and_deduplicates_delivery(): void
    {
        [$payload,$remote,$extras] = $this->webhookFixture();
        $this->http->responses = [$remote, ['object' => 'list', 'data' => [], 'has_more' => false, 'url' => '/v1/invoices'], $remote, $extras];
        $listener = app(SyncStripePlanSubscription::class);
        $listener->handle(new WebhookHandled($payload));
        $listener->handle(new WebhookHandled($payload));
        $this->assertCount(4, $this->http->requests);
        $this->assertDatabaseHas('billing_webhook_events', ['stripe_event_id' => 'evt_test', 'status' => 'completed']);
        $this->assertSame(1, BillingOutbox::where('kind', 'provision_extras')->count());
    }

    public function test_checkout_expired_webhook_closes_pending_contract_idempotently(): void
    {
        $catalog = app(CatalogService::class);
        $contracts = app(ContractService::class);
        $this->tenant->forceFill(['stripe_id' => 'cus_remote'])->save();
        $pending = $contracts->create($this->tenant, $catalog->price('business'));
        $op = $contracts->enqueue($this->tenant, 'checkout', 'expired-webhook', ['contract_id' => $pending->id, 'email' => 'test@example.test']);
        $op->update(['status' => 'completed', 'result' => ['url' => 'https://checkout.stripe.test/expired', 'session_id' => 'cs_expired']]);
        $payload = [
            'id' => 'evt_checkout_expired',
            'type' => 'checkout.session.expired',
            'livemode' => false,
            'data' => ['object' => [
                'id' => 'cs_expired',
                'object' => 'checkout.session',
                'customer' => 'cus_remote',
                'status' => 'expired',
                'payment_status' => 'unpaid',
                'metadata' => [
                    'tenant_public_id' => $this->tenant->public_id,
                    'contract_public_id' => $pending->public_id,
                ],
            ]],
        ];
        $this->http->responses = [
            $this->checkoutSession('cs_expired', 'expired', 'unpaid', $pending),
        ];

        $listener = app(SyncStripePlanSubscription::class);
        app(CurrentTenant::class)->clear();
        $listener->handle(new WebhookReceived($payload));
        $listener->handle(new WebhookReceived($payload));

        $this->assertFalse(
            app(CurrentTenant::class)->isResolved()
        );
        app(CurrentTenant::class)->run(
            $this->tenant,
            function () use ($pending): void {
                $this->assertNotNull(
                    $pending->refresh()->ended_at
                );
                $this->assertSame(
                    SubscriptionStatus::Cancelled,
                    $pending->status
                );
            }
        );
        $this->assertCount(1, $this->http->requests);
        $this->assertDatabaseHas('billing_webhook_events', ['stripe_event_id' => 'evt_checkout_expired', 'status' => 'completed']);
    }

    public function test_webhook_network_failure_is_not_marked_processed_and_can_retry(): void
    {
        [$payload,$remote,$extras] = $this->webhookFixture();
        $this->http->responses = [new \RuntimeException('Offline')];
        $listener = app(SyncStripePlanSubscription::class);
        try {
            $listener->handle(new WebhookHandled($payload));
        } catch (\RuntimeException) {
        }
        $this->assertDatabaseHas('billing_webhook_events', ['stripe_event_id' => 'evt_test', 'status' => 'failed']);
        $this->assertSame(0, BillingOutbox::where('kind', 'provision_extras')->count());
        $this->http->responses = [$remote, ['object' => 'list', 'data' => [], 'has_more' => false, 'url' => '/v1/invoices'], $remote, $extras];
        $listener->handle(new WebhookHandled($payload));
        $this->assertDatabaseHas('billing_webhook_events', ['stripe_event_id' => 'evt_test', 'status' => 'completed']);
    }

    public function test_pending_update_webhook_is_processed_from_webhook_received(): void
    {
        [$payload, $remote, $extras] = $this->webhookFixture();
        $payload['id'] = 'evt_pending_update';
        $payload['type'] = 'customer.subscription.pending_update_applied';
        $this->http->responses = [
            $remote,
            [
                'object' => 'list',
                'data' => [],
                'has_more' => false,
                'url' => '/v1/invoices',
            ],
            $remote,
            $extras,
        ];

        app(SyncStripePlanSubscription::class)
            ->handle(new WebhookReceived($payload));

        $this->assertDatabaseHas('billing_webhook_events', [
            'stripe_event_id' => 'evt_pending_update',
            'status' => 'completed',
        ]);
    }

    public function test_expired_pending_update_cancels_immediate_change_without_preselected_tenant(): void
    {
        [$payload, $remote] = $this->webhookFixture();
        $subscription = PlanSubscription::query()
            ->where(
                'public_id',
                data_get(
                    $remote,
                    'metadata.contract_public_id'
                )
            )
            ->firstOrFail();

        foreach ([
            'base' => 'sub_remote',
            'extras' => 'sub_extras',
        ] as $role => $stripeId) {
            BillingSubscriptionLink::query()->create([
                'plan_subscription_id' => $subscription->id,
                'role' => $role,
                'stripe_subscription_id' => $stripeId,
                'status' => 'active',
                'period_starts_at' => now(),
                'period_ends_at' => now()->addMonth(),
            ]);
        }

        app(ContractService::class)->activate($subscription);
        $change = SubscriptionChange::query()->create([
            'plan_subscription_id' => $subscription->id,
            'kind' => 'plan',
            'change_mode' => 'immediate',
            'status' => 'processing',
            'target_plan_price_id' => app(CatalogService::class)
                ->price('restaurant')
                ->id,
            'effective_at' => now(),
        ]);

        BillingOutbox::query()->create([
            'operation_key' => 'upgrade-expiration-test',
            'kind' => 'upgrade_plan',
            'payload' => ['change_id' => $change->id],
            'result' => [
                'status' => 'awaiting_payment',
                'invoice_id' => 'in_test',
            ],
            'status' => 'completed',
            'available_at' => now(),
        ]);

        $payload['id'] = 'evt_pending_update_expired';
        $payload['type'] =
            'customer.subscription.pending_update_expired';
        $payload['data']['object'] = $remote;
        $this->http->responses = [
            $remote,
            [
                'object' => 'list',
                'data' => [],
                'has_more' => false,
                'url' => '/v1/invoices',
            ],
        ];

        app(CurrentTenant::class)->clear();
        app(SyncStripePlanSubscription::class)
            ->handle(new WebhookReceived($payload));

        $this->assertFalse(
            app(CurrentTenant::class)->isResolved()
        );

        app(CurrentTenant::class)->run(
            $this->tenant,
            function () use ($subscription, $change): void {
                $this->assertSame(
                    'cancelled',
                    $change->refresh()->status
                );
                $this->assertNotNull($change->completed_at);

                $current = app(EntitlementService::class)
                    ->current($this->tenant);

                $this->assertSame($subscription->id, $current?->id);
                $this->assertSame(
                    'business',
                    $current?->version->plan->code
                );
            }
        );
        $this->assertDatabaseHas('billing_webhook_events', [
            'stripe_event_id' => 'evt_pending_update_expired',
            'status' => 'completed',
        ]);
    }

    private function webhookFixture(): array
    {
        $this->tenant->forceFill(['stripe_id' => 'cus_remote'])->save();
        $catalog = app(CatalogService::class);
        $s = app(ContractService::class)->create($this->tenant, $catalog->price('business'));
        $remote = ['id' => 'sub_remote', 'object' => 'subscription', 'customer' => 'cus_remote', 'status' => 'active', 'start_date' => now()->timestamp, 'billing_cycle_anchor' => now()->timestamp, 'current_period_start' => now()->timestamp, 'current_period_end' => now()->addMonthNoOverflow()->timestamp, 'cancel_at_period_end' => false, 'pending_update' => null, 'latest_invoice' => ['object' => 'invoice', 'id' => 'in_test', 'status' => 'paid'], 'metadata' => ['contract_public_id' => $s->public_id, 'billing_role' => 'base'], 'items' => ['object' => 'list', 'data' => [['id' => 'si_base', 'object' => 'subscription_item', 'quantity' => 1, 'price' => ['id' => $catalog->mapping($s->price)->stripe_price_id, 'object' => 'price']]]]];
        $addon = $s->addons()->firstOrFail();
        $extras = ['id' => 'sub_extras', 'object' => 'subscription', 'customer' => 'cus_remote', 'status' => 'active', 'start_date' => now()->timestamp, 'billing_cycle_anchor' => now()->timestamp, 'current_period_start' => now()->timestamp, 'current_period_end' => now()->addMonthNoOverflow()->timestamp, 'cancel_at_period_end' => false, 'pending_update' => null, 'latest_invoice' => ['object' => 'invoice', 'id' => 'in_extras', 'status' => 'paid'], 'metadata' => ['contract_public_id' => $s->public_id, 'billing_role' => 'extras'], 'items' => ['object' => 'list', 'data' => [['id' => 'si_extras', 'object' => 'subscription_item', 'quantity' => 1, 'price' => ['id' => $catalog->mapping($addon->price)->stripe_price_id, 'object' => 'price', 'recurring' => ['usage_type' => 'metered']]]]]];

        return [['id' => 'evt_test', 'type' => 'customer.subscription.updated', 'livemode' => false, 'data' => ['object' => $remote]], $remote, $extras];
    }

    private function checkoutSession(
        string $id,
        string $status,
        string $paymentStatus,
        ?PlanSubscription $contract = null
    ): array {
        $contract ??= $this->tenant->planSubscriptions()->latest('id')->firstOrFail();

        return [
            'id' => $id,
            'object' => 'checkout.session',
            'customer' => $contract->tenant->stripe_id ?? 'cus_remote',
            'livemode' => false,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'subscription' => null,
            'metadata' => [
                'contract_public_id' => $contract->public_id,
                'tenant_public_id' => $contract->tenant->public_id,
            ],
        ];
    }

    private function idempotency(array $headers): string
    {
        return collect($headers)->first(fn ($h) => str_starts_with(strtolower($h), 'idempotency-key:'));
    }
}
class StripeTestHttp implements ClientInterface
{
    public array $responses = [];

    public array $requests = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = compact('method', 'absUrl', 'headers', 'params');
        if (! $this->responses) {
            throw new \RuntimeException('Unexpected Stripe request '.$absUrl);
        }
        $response = array_shift($this->responses);
        if ($response instanceof \Throwable) {
            throw $response;
        }

        return [json_encode($response), 200, []];
    }
}
