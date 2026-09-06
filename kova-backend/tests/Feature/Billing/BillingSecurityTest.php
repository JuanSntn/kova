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

    public function test_owner_can_request_checkout_but_cannot_choose_stripe_price_or_tenant(): void
    {
        [$owner, $tenant] = $this->provisionOwner();
        $gateway = new FakeBillingGateway;
        $this->app->instance(BillingGateway::class, $gateway);

        $this->actingAs($owner, 'web')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonFragment([
                'abilities' => ['billing.manage', 'billing.view'],
            ]);

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->postJson('/api/billing/checkout', [
                'plan' => PlanCode::Business->value,
            ])
            ->assertCreated()
            ->assertExactJson(['url' => 'https://checkout.stripe.test/session']);

        $this->assertSame(PlanCode::Business, $gateway->requestedPlan);
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
            ->assertJsonPath('tenants.0.abilities', []);

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
        $plan = Plan::query()->where('code', PlanCode::Free->value)->sole();
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

    public ?PlanCode $requestedPlan = null;

    public function createCheckout(
        Tenant $tenant,
        User $user,
        PlanCode $plan,
        string $operationId,
    ): string
    {
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

    public function cancelSubscription(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function reactivateSubscription(Tenant $tenant): void
    {
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
