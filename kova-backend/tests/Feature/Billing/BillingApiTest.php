<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Contracts\BillingGateway;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Models\AddonPrice;
use App\Models\Plan;
use App\Models\SubscriptionChange;
use App\Models\User;
use App\Services\Subscriptions\CatalogService;
use App\Services\Subscriptions\ContractService;
use App\Tenancy\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingApiTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): array
    {
        $this->seed([PlanSeeder::class, RoleSeeder::class]);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $tenant = app(CreateTenant::class)->handle($user, Plan::where('code', 'free')->firstOrFail(), 'API', 'api');
        $this->actingAs($user, 'web')->withHeader('X-Tenant-ID', $tenant->public_id);

        return [$user, $tenant];
    }

    public function test_offers_and_summary_keep_legacy_fields_and_expose_annual_prices(): void
    {
        $this->owner();
        $this->getJson('/api/billing/offers')->assertOk()->assertJsonPath('data.1.code', 'business')->assertJsonPath('data.1.monthly_price', '129.00')->assertJsonPath('data.1.monthly_sales_limit', '150000.00')->assertJsonPath('data.1.prices.1.interval', 'year')->assertJsonPath('data.1.prices.1.amount', 129000)->assertJsonPath('data.1.prices.1.available', false);
        $this->getJson('/api/billing')->assertOk()->assertJsonPath('current_plan', 'free')->assertJsonPath('current_subscription', null)->assertJsonPath('entitlements.max_categories', 5)->assertJsonStructure(['current_plan_name', 'payment_method', 'billing_details', 'actions', 'stripe', 'plans', 'usage_period', 'pending_changes']);
        $this->getJson('/api/billing/changes')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_summary_uses_contracted_addons_even_after_offer_retirement(): void
    {
        [$user, $tenant] = $this->owner();
        $contract = app(CurrentTenant::class)->run($tenant, function () use ($tenant) {
            $tenant->planSubscriptions()->whereNotNull('current_tenant_id')->update(['current_tenant_id' => null, 'ended_at' => now()]);
            $price = app(CatalogService::class)->price('restaurant', 'year');
            $s = app(ContractService::class)->create($tenant, $price);
            $s->update([
                'current_tenant_id' => $tenant->id,
                'pending_tenant_id' => null,
                'status' => 'active',
            ]);
            $price->version->update(['status' => 'retired']);

            return $s;
        });
        $response = $this->getJson('/api/billing')->assertOk()
            ->assertJsonPath('contract.offer_id', $contract->price->public_id)
            ->assertJsonPath('current_subscription.interval', 'year')
            ->assertJsonPath('current_subscription.amount', 599000)
            ->assertJsonPath('actions.can_manage', true)
            ->assertJsonPath('capacity_usage.included_branches', 1);
        $this->assertContains('extra_branch', array_column($response->json('available_addons'), 'code'));
        $this->assertNotContains('restaurant', array_column($response->json('plans'), 'code'));
        $this->assertNotContains('id', array_keys($response->json('available_addons.0')));
    }

    public function test_pending_change_exposes_destination_but_waits_for_billing_confirmation(): void
    {
        [$user, $tenant] = $this->owner();
        [$change, $op] = app(CurrentTenant::class)->run($tenant, function () use ($tenant) {
            $s = $tenant->planSubscriptions()->whereNotNull('current_tenant_id')->firstOrFail();
            $target = app(CatalogService::class)->price('business', 'year');
            $change = SubscriptionChange::create(['plan_subscription_id' => $s->id, 'kind' => 'plan', 'target_plan_price_id' => $target->id, 'effective_at' => now()->addMonth()]);
            $op = app(ContractService::class)->enqueue($tenant, 'schedule_plan', 'preview-test', ['change_id' => $change->id]);

            return [$change, $op];
        });
        $this->getJson('/api/billing')->assertOk()->assertJsonPath('pending_changes.0.target_name', 'Negocio')->assertJsonPath('pending_changes.0.target_amount', 129000)->assertJsonPath('pending_changes.0.confirmed', false);
        app(CurrentTenant::class)->run($tenant, fn () => $op->update(['status' => 'completed']));
        $this->getJson('/api/billing/changes')->assertOk()->assertJsonPath('data.0.confirmed', true)->assertJsonPath('data.0.id', $change->public_id);
    }

    public function test_branch_selection_data_is_scoped_to_the_current_tenant(): void
    {
        [$user, $tenant] = $this->owner();
        $other = app(CreateTenant::class)->handle($user, Plan::where('code', 'free')->firstOrFail(), 'Other', 'other');
        $foreignId = app(CurrentTenant::class)->run($other, fn () => $other->branches()->firstOrFail()->public_id);
        $response = $this->getJson('/api/billing')->assertOk();
        $this->assertNotContains($foreignId, array_column($response->json('branches'), 'id'));
        $this->assertCount(1, $response->json('branches'));
    }

    public function test_view_permission_does_not_grant_billing_management(): void
    {
        [$user, $tenant] = $this->owner();
        app(CurrentTenant::class)->run($tenant, function () use ($user, $tenant) {
            setPermissionsTeamId($tenant->id);
            $user->syncRoles([]);
            $user->givePermissionTo('billing.view');
        });
        $this->getJson('/api/billing')->assertOk()->assertJsonPath('actions.can_manage', false);
        $this->postJson('/api/billing/checkout', ['plan' => 'business'])->assertForbidden();
        $this->postJson('/api/billing/checkout/cancel-pending')->assertForbidden();
        $this->postJson('/api/billing/reconcile')->assertForbidden();
        $this->postJson('/api/billing/addons', [])->assertForbidden();
        $this->postJson('/api/billing/cancel')->assertForbidden();
    }

    public function test_unpaid_addon_survives_reload_without_exposing_internal_operation_data(): void
    {
        [$user, $tenant] = $this->owner();
        app(CurrentTenant::class)->run($tenant, function () use ($tenant) {
            $s = $tenant->planSubscriptions()->whereNotNull('current_tenant_id')->firstOrFail();
            $price = AddonPrice::whereHas('version.addon', fn ($q) => $q->where('code', 'extra_branch'))->firstOrFail();
            $op = app(ContractService::class)->enqueue($tenant, 'add_addon', 'pending-ui-test', ['contract_id' => $s->id, 'addon_price_id' => $price->id, 'quantity' => 2]);
            $op->update(['status' => 'completed', 'result' => ['status' => 'awaiting_payment', 'invoice_url' => 'https://invoice.stripe.test/example', 'internal_secret' => 'hidden']]);
        });
        $this->getJson('/api/billing')->assertOk()
            ->assertJsonPath('pending_addon_operations.0.status', 'awaiting_payment')
            ->assertJsonPath('pending_addon_operations.0.quantity', 2)
            ->assertJsonPath('pending_addon_operations.0.invoice_url', 'https://invoice.stripe.test/example')
            ->assertJsonMissingPath('pending_addon_operations.0.internal_secret')
            ->assertJsonMissingPath('pending_addon_operations.0.payload');
    }

    public function test_pending_checkout_summary_exposes_domain_state_without_internal_ids(): void
    {
        [$user, $tenant] = $this->owner();
        app(CurrentTenant::class)->run($tenant, function () use ($tenant, $user) {
            $price = app(CatalogService::class)->price('business');
            $contract = app(ContractService::class)->create($tenant, $price);
            $op = app(ContractService::class)->enqueue($tenant, 'checkout', 'summary-open', [
                'contract_id' => $contract->id,
                'email' => $user->email,
            ]);
            $op->update([
                'status' => 'completed',
                'result' => [
                    'url' => 'https://checkout.stripe.test/open',
                    'session_id' => 'cs_open',
                    'checkout_status' => 'open',
                    'payment_status' => 'unpaid',
                    'state' => 'awaiting_customer',
                ],
            ]);
        });

        $this->getJson('/api/billing')->assertOk()
            ->assertJsonPath('pending_contracts.0.state', 'awaiting_customer')
            ->assertJsonPath('pending_contracts.0.can_resume', true)
            ->assertJsonPath('pending_contracts.0.can_cancel', true)
            ->assertJsonPath('pending_contracts.0.message', 'El pago todavía no fue realizado.')
            ->assertJsonMissingPath('pending_contracts.0.session_id')
            ->assertJsonMissingPath('pending_contracts.0.url');
    }

    public function test_annual_checkout_uses_server_resolved_plan_and_optional_interval(): void
    {
        [$user,$tenant] = $this->owner();
        $gateway = \Mockery::mock(BillingGateway::class);
        $gateway->shouldReceive('createCheckout')->once()->withArgs(fn ($t, $u, $plan, $key, $interval, $offer, $branches) => $t->id === $tenant->id && $u->id === $user->id && $plan === 'business' && $interval === 'year' && $offer === null && $branches === [])->andReturn('https://checkout.stripe.test/session');
        $this->app->instance(BillingGateway::class, $gateway);
        $this->postJson('/api/billing/checkout', ['plan' => 'business', 'interval' => 'year'])->assertCreated()->assertJsonPath('url', 'https://checkout.stripe.test/session');
        $this->postJson('/api/billing/checkout', ['plan' => 'business', 'interval' => 'week'])->assertUnprocessable();
    }

    public function test_read_only_grace_expiry_blocks_writes_but_allows_billing_and_queries(): void
    {
        [$user,$tenant] = $this->owner();
        app(CurrentTenant::class)->run($tenant, fn () => $tenant->planSubscriptions()->whereNotNull('current_tenant_id')->firstOrFail()->update(['grace_ends_at' => now()->subSecond(), 'status' => 'suspended']));
        $this->postJson('/api/pos/categories', ['name' => 'Blocked'])->assertConflict();
        $this->getJson('/api/billing')->assertOk();
        $this->getJson('/api/navigation')->assertOk();
    }

    public function test_retired_or_unknown_addon_does_not_escape_tenant_authorization(): void
    {
        $this->owner();
        $this->patchJson('/api/billing/addons/'.Str::uuid(), ['quantity' => 0])->assertNotFound();
        $this->deleteJson('/api/billing/changes/'.Str::uuid())->assertNotFound();
    }
}
