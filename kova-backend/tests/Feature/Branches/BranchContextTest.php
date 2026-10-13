<?php

namespace Tests\Feature\Branches;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Enums\PlanCode;
use App\Models\Branch;
use App\Models\BranchProductSetting;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentBranch;
use App\Tenancy\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchContextTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\QuotesPosCheckout;

    public function test_single_operable_branch_is_resolved_without_header_and_multiple_require_selection(): void
    {
        [$owner, $tenant] = $this->owner('uno');
        $product = $this->product($tenant);

        $this->tenant($owner, $tenant)->postCheckout($this->order($product))->assertCreated();

        $second = app(CurrentTenant::class)->run($tenant, fn () => Branch::query()->create([
            'code' => 'DOS', 'name' => 'Dos', 'timezone' => 'America/Mexico_City', 'is_active' => true, 'billing_enabled' => true,
        ]));

        $this->tenant($owner, $tenant)->postCheckout($this->order($product))
            ->assertConflict()->assertJsonPath('code', 'POS_BRANCH_NOT_RESOLVED');

        $this->tenant($owner, $tenant)->withHeader('X-Branch-ID', $second->public_id)
            ->postCheckout($this->order($product))->assertCreated();
    }

    public function test_branch_override_controls_effective_price_and_availability_in_backend(): void
    {
        [$owner, $tenant] = $this->owner('precio');
        $product = $this->product($tenant);
        $branch = app(CurrentTenant::class)->run($tenant, fn () => Branch::query()->sole());

        BranchProductSetting::query()->create(['branch_id' => $branch->id, 'product_id' => $product->id, 'is_available' => true, 'price_override' => '70.00']);
        $this->tenant($owner, $tenant)->postCheckout($this->order($product))
            ->assertCreated()->assertJsonPath('data.total', '70.00');

        BranchProductSetting::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->update(['is_available' => false]);
        $this->tenant($owner, $tenant)->postCheckout($this->order($product))->assertUnprocessable();
    }

    public function test_context_excludes_disabled_branch_and_cross_tenant_header_is_not_resolved(): void
    {
        [$owner, $tenant] = $this->owner('principal');
        [, $other] = $this->owner('otro');
        $product = $this->product($tenant);
        $otherBranch = app(CurrentTenant::class)->run($other, fn () => Branch::query()->sole());

        $this->tenant($owner, $tenant)->getJson('/api/context/branches')->assertOk()->assertJsonCount(1, 'data');
        $this->tenant($owner, $tenant)->withHeader('X-Branch-ID', $otherBranch->public_id)
            ->postCheckout($this->order($product))->assertNotFound();

        app(CurrentTenant::class)->run($tenant, fn () => Branch::query()->update(['billing_enabled' => false]));
        $this->tenant($owner, $tenant)->getJson('/api/context/branches')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_operational_catalog_excludes_inactive_and_plan_blocked_categories(): void
    {
        [$owner, $tenant] = $this->owner('categorias');
        $product = $this->product($tenant);
        app(CurrentTenant::class)->run($tenant, function () use ($tenant): void {
            $tenant->categories()->create(['name' => 'Inactiva', 'is_active' => false, 'billing_enabled' => true]);
            $tenant->categories()->create(['name' => 'Bloqueada', 'is_active' => true, 'billing_enabled' => false]);
        });

        $this->tenant($owner, $tenant)
            ->getJson('/api/pos/catalog/categories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $product->category->public_id);
    }

    public function test_current_branch_is_cleared_after_a_downstream_validation_error(): void
    {
        [$owner, $tenant] = $this->owner('limpieza');

        $this->tenant($owner, $tenant)
            ->postCheckout(['order_type' => 'counter'])
            ->assertUnprocessable();

        $this->assertFalse(app(CurrentBranch::class)->isResolved());
    }

    private function owner(string $slug): array
    {
        $this->seed([PlanSeeder::class, RoleSeeder::class]);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $plan = Plan::query()->where('code', PlanCode::Free->value)->sole();

        return [$user, app(CreateTenant::class)->handle($user, $plan, ucfirst($slug), $slug)];
    }

    private function product(Tenant $tenant): Product
    {
        return app(CurrentTenant::class)->run($tenant, function () use ($tenant) {
            $category = $tenant->categories()->create(['name' => 'Bebidas', 'is_active' => true]);

            return $tenant->products()->create(['category_id' => $category->id, 'name' => 'Café', 'price' => '65.00', 'is_active' => true]);
        });
    }

    private function order(Product $product): array
    {
        return ['order_type' => 'counter', 'payment_method' => 'card', 'items' => [['product_id' => $product->public_id, 'quantity' => 1]]];
    }

    private function tenant(User $user, Tenant $tenant)
    {
        return $this->actingAs($user, 'web')->withHeader('X-Tenant-ID', $tenant->public_id);
    }
}
