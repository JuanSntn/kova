<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Contracts\BillingGateway;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Enums\MembershipStatus;
use App\Enums\PlanCode;
use App\Enums\RoleName;
use App\Models\Membership;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_rate_limits_are_isolated_by_operation(): void
    {
        [$owner, $tenant] = $this->provisionOwner();
        $gateway = new FakeBillingGateway;
        $this->app->instance(BillingGateway::class, $gateway);

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/billing/checkout', [
                'plan' => PlanCode::Business->value,
            ])->assertCreated();
        }

        $this->postJson('/api/billing/reactivate')
            ->assertOk();

        $this->postJson('/api/billing/reconcile')
            ->assertOk();

        $this->assertSame(5, $gateway->checkoutCalls);
        $this->assertSame(1, $gateway->reactivateCalls);
    }

    public function test_repeated_billing_operation_returns_a_safe_rate_limit_response(): void
    {
        [$owner, $tenant] = $this->provisionOwner();
        $gateway = new FakeBillingGateway;
        $this->app->instance(BillingGateway::class, $gateway);

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/billing/checkout', [
                'plan' => PlanCode::Business->value,
            ])->assertCreated();
        }

        $response = $this->postJson('/api/billing/checkout', [
            'plan' => PlanCode::Business->value,
        ]);

        $response
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertJsonPath('code', 'rate_limit_exceeded')
            ->assertJsonStructure([
                'message',
                'retry_after',
                'request_id',
            ])
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('file')
            ->assertJsonMissingPath('trace');

        $this->assertSame(5, $gateway->checkoutCalls);
    }

    public function test_billing_rate_limits_are_isolated_by_tenant_and_user(): void
    {
        [$owner, $tenant] = $this->provisionOwner();
        $gateway = new FakeBillingGateway;
        $this->app->instance(BillingGateway::class, $gateway);

        $requestCheckout = function (User $user, Tenant $requestTenant): void {
            $this->app['auth']->forgetGuards();
            $this->actingAs($user, 'web')
                ->withHeader('X-Tenant-ID', $requestTenant->public_id)
                ->postJson('/api/billing/checkout', [
                    'plan' => PlanCode::Business->value,
                ])
                ->assertCreated();
        };

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $requestCheckout($owner, $tenant);
        }

        $secondTenant = app(CreateTenant::class)->handle(
            $owner,
            Plan::where('code', PlanCode::Free->value)->firstOrFail(),
            'Secundario',
            'secundario',
        );
        $requestCheckout($owner, $secondTenant);

        $secondOwner = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        app(CurrentTenant::class)->run($tenant, function () use ($secondOwner, $tenant): void {
            Membership::query()->create([
                'tenant_id' => $tenant->getKey(),
                'user_id' => $secondOwner->getKey(),
                'status' => MembershipStatus::Active,
                'joined_at' => now(),
            ]);

            setPermissionsTeamId($tenant->getKey());
            $secondOwner->assignRole(RoleName::Owner);
        });
        setPermissionsTeamId(null);
        $this->assertNotSame($owner->getKey(), $secondOwner->getKey());
        $requestCheckout($secondOwner, $tenant);

        $this->assertSame(7, $gateway->checkoutCalls);
    }

    public function test_billing_summary_polling_does_not_consume_mutation_limit(): void
    {
        [$owner, $tenant] = $this->provisionOwner();
        $gateway = new FakeBillingGateway;
        $this->app->instance(BillingGateway::class, $gateway);

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->getJson('/api/billing')->assertOk();
        }

        $this->postJson('/api/billing/checkout', [
            'plan' => PlanCode::Business->value,
        ])->assertCreated();

        $this->assertSame(1, $gateway->checkoutCalls);
    }

    public function test_owner_can_request_checkout_but_cannot_choose_stripe_price_or_tenant(): void
    {
        [$owner, $tenant] = $this->provisionOwner();
        $gateway = new FakeBillingGateway;
        $this->app->instance(BillingGateway::class, $gateway);

        $this->actingAs($owner, 'web')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('tenants.0.abilities', fn ($abilities) => in_array('billing.manage', $abilities) && in_array('billing.view', $abilities));

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->postJson('/api/billing/checkout', [
                'plan' => PlanCode::Business->value,
            ])
            ->assertCreated()
            ->assertExactJson(['url' => 'https://checkout.stripe.test/session']);

        $this->assertSame(PlanCode::Business->value, $gateway->requestedPlan);
        $this->assertTrue($tenant->is($gateway->tenant));
        $this->assertTrue($owner->is($gateway->user));

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->postJson('/api/billing/checkout', [
                'plan' => PlanCode::Business->value,
                'price_id' => 'price_manipulated',
                'tenant_id' => 999,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price_id', 'tenant_id']);
    }

    public function test_member_without_billing_permission_gets_403(): void
    {
        [$owner, $tenant] = $this->provisionOwner();
        $cashier = User::factory()->create(['email_verified_at' => now()]);

        app(CurrentTenant::class)->run($tenant, function () use ($cashier, $tenant): void {
            Membership::query()->create([
                'tenant_id' => $tenant->getKey(),
                'user_id' => $cashier->getKey(),
                'status' => MembershipStatus::Active,
                'joined_at' => now(),
            ]);

            setPermissionsTeamId($tenant->getKey());
            $cashier->assignRole(RoleName::Cashier);
        });
        setPermissionsTeamId(null);

        setPermissionsTeamId($tenant->getKey());
        $cashier->unsetRelation('roles');
        $cashier->unsetRelation('permissions');
        $this->assertSame([RoleName::Cashier->value], $cashier->getRoleNames()->all());
        $this->assertFalse($cashier->can('billing.view'));
        setPermissionsTeamId(null);

        $summaryResponse = $this->actingAs($cashier, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->getJson('/api/billing');
        $this->assertSame(403, $summaryResponse->status(), 'Billing summary must be owner-only.');

        $checkoutResponse = $this->actingAs($cashier, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->postJson('/api/billing/checkout', ['plan' => 'business']);
        $this->assertSame(403, $checkoutResponse->status(), 'Billing checkout must be owner-only.');

        $this->actingAs($cashier, 'web')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('tenants.0.abilities', ['cash.view', 'cash.operate', 'orders.create']);

        $this->assertNotNull($owner);
    }

    public function test_changing_tenant_header_does_not_grant_billing_access(): void
    {
        [$firstOwner] = $this->provisionOwner();
        [, $secondTenant] = $this->provisionOwner('second@example.test', 'Segundo');

        $this->actingAs($firstOwner, 'web')
            ->withHeader('X-Tenant-ID', $secondTenant->public_id)
            ->getJson('/api/billing')
            ->assertNotFound();
    }

    /**
     * @return array{User, Tenant}
     */
    private function provisionOwner(
        string $email = 'owner@example.test',
        string $business = 'Principal',
    ): array {
        $this->seed([PlanSeeder::class, RoleSeeder::class]);

        $owner = User::factory()->create([
            'email' => $email,
            'email_verified_at' => now(),
        ]);
        $plan = Plan::factory()->create();
        $tenant = app(CreateTenant::class)->handle(
            $owner,
            $plan,
            $business,
            str($business)->slug()->toString(),
        );

        return [$owner, $tenant];
    }
}

class FakeBillingGateway implements BillingGateway
{
    public ?Tenant $tenant = null;

    public ?User $user = null;

    public ?string $requestedPlan = null;

    public int $checkoutCalls = 0;

    public int $reactivateCalls = 0;

    public function createCheckout(
        Tenant $tenant,
        User $user,
        string $plan,
        string $operationId,
        string $interval = 'month',
        ?string $offer = null,
        array $retainedBranches = [],
        array $retainedMemberships = [],
    ): string {
        $this->checkoutCalls++;
        $this->tenant = $tenant;
        $this->user = $user;
        $this->requestedPlan = $plan;

        return 'https://checkout.stripe.test/session';
    }

    public function createPortal(Tenant $tenant): string
    {
        $this->tenant = $tenant;

        return 'https://billing.stripe.test/portal';
    }

    public function cancelSubscription(Tenant $tenant, array $retainedBranches = [], array $retainedMemberships = []): void
    {
        $this->tenant = $tenant;
    }

    public function reactivateSubscription(Tenant $tenant): void
    {
        $this->reactivateCalls++;
        $this->tenant = $tenant;
    }

    public function listInvoices(
        Tenant $tenant,
        int $limit = 5,
        ?string $startingAfter = null,
    ): array {
        $this->tenant = $tenant;

        return [];
    }
}
