<?php

namespace Tests\Feature\Pos;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Enums\PlanCode;
use App\Models\BillingSubscriptionLink;
use App\Models\Category;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Subscriptions\CatalogAccessService;
use App\Tenancy\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSubscriptionAccessTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\QuotesPosCheckout;

    public function test_blocked_products_remain_visible_but_cannot_be_modified_or_sold(): void
    {
        [$owner, $tenant] = $this->provisionOwner();

        [$category, $product] = app(CurrentTenant::class)->run(
            $tenant,
            function () use ($tenant): array {
                $category = $tenant->categories()->create([
                    'name' => 'Especiales',
                    'sort_order' => 1,
                    'is_active' => true,
                    'billing_enabled' => true,
                ]);

                $product = $tenant->products()->create([
                    'category_id' => $category->id,
                    'name' => 'Pizza grande',
                    'description' => null,
                    'price' => '150.00',
                    'image_path' => null,
                    'sort_order' => 1,
                    'is_active' => true,
                    'billing_enabled' => false,
                ]);

                return [$category, $product];
            }
        );

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->getJson('/api/pos/products')
            ->assertOk()
            ->assertJsonPath('data.0.id', $product->public_id)
            ->assertJsonPath('data.0.locked_by_plan', true)
            ->assertJsonPath('data.0.can_edit', false)
            ->assertJsonPath('data.0.can_delete', false);

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->putJson('/api/pos/products/'.$product->public_id, [
                'name' => 'Pizza alterada desde Burp',
                'category_id' => $category->public_id,
                'price' => 180,
                'is_active' => true,
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'PLAN_LIMIT_EXCEEDED');

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->deleteJson('/api/pos/products/'.$product->public_id)
            ->assertConflict()
            ->assertJsonPath('code', 'PLAN_LIMIT_EXCEEDED');

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->postCheckout([
                'order_type' => 'counter',
                'payment_method' => 'cash',
                'amount_received' => 20000,
                'items' => [
                    [
                        'product_id' => $product->public_id,
                        'quantity' => 1,
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');
    }

    public function test_blocked_categories_remain_visible_but_cannot_be_modified_or_used_for_products(): void
    {
        [$owner, $tenant] = $this->provisionOwner();

        $category = app(CurrentTenant::class)->run(
            $tenant,
            fn () => $tenant->categories()->create([
                'name' => 'Reservada',
                'sort_order' => 1,
                'is_active' => true,
                'billing_enabled' => false,
            ])
        );

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->getJson('/api/pos/categories')
            ->assertOk()
            ->assertJsonPath('data.0.id', $category->public_id)
            ->assertJsonPath('data.0.locked_by_plan', true)
            ->assertJsonPath('data.0.can_edit', false)
            ->assertJsonPath('data.0.can_delete', false);

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->putJson('/api/pos/categories/'.$category->public_id, [
                'name' => 'Intento editado',
                'sort_order' => 2,
                'is_active' => true,
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'PLAN_LIMIT_EXCEEDED');

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->postJson('/api/pos/products', [
                'name' => 'Producto inválido',
                'category_id' => $category->public_id,
                'price' => 99,
                'is_active' => true,
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'PLAN_LIMIT_EXCEEDED');
    }

    public function test_catalog_sync_locks_surplus_and_reactivates_with_higher_capacity(): void
    {
        [$owner, $tenant] = $this->provisionOwner();

        app(CurrentTenant::class)->run(
            $tenant,
            function () use ($tenant): void {
                for ($i = 1; $i <= 7; $i++) {
                    $category = $tenant->categories()->create([
                        'name' => 'Categoría '.$i,
                        'sort_order' => $i,
                        'is_active' => true,
                        'billing_enabled' => true,
                    ]);

                    $tenant->products()->create([
                        'category_id' => $category->id,
                        'name' => 'Producto '.$i,
                        'description' => null,
                        'price' => '50.00',
                        'image_path' => null,
                        'sort_order' => $i,
                        'is_active' => true,
                        'billing_enabled' => true,
                    ]);
                }
            }
        );

        app(CurrentTenant::class)->run(
            $tenant,
            fn () => app(CatalogAccessService::class)->syncTenant($tenant)
        );

        $this->assertSame(
            5,
            $tenant->categories()->where('billing_enabled', true)->count()
        );
        $this->assertSame(
            5,
            $tenant->products()->where('billing_enabled', true)->count()
        );

        $business = Plan::query()
            ->where('code', PlanCode::Business->value)
            ->sole();

        app(CurrentTenant::class)->run(
            $tenant,
            function () use ($tenant, $business): void {
                $contract = app(\App\Services\Subscriptions\ContractService::class)
                    ->create(
                        $tenant,
                        app(\App\Services\Subscriptions\CatalogService::class)
                            ->price((string) $business->code),
                    );

                foreach (['base', 'extras'] as $role) {
                    BillingSubscriptionLink::query()->create([
                        'plan_subscription_id' => $contract->id,
                        'role' => $role,
                        'stripe_subscription_id' => 'sub_'.$role.'_'.strtolower((string) \Illuminate\Support\Str::random(10)),
                        'status' => 'active',
                        'period_starts_at' => now(),
                        'period_ends_at' => now()->addMonth(),
                    ]);
                }

                app(\App\Services\Subscriptions\ContractService::class)
                    ->activate($contract);
            }
        );

        $this->assertSame(
            7,
            $tenant->categories()->where('billing_enabled', true)->count()
        );
        $this->assertSame(
            7,
            $tenant->products()->where('billing_enabled', true)->count()
        );

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->getJson('/api/pos/products')
            ->assertOk()
            ->assertJsonPath('meta.usage.used', 7)
            ->assertJsonPath('meta.category_usage.used', 7);
    }

    public function test_created_product_and_category_are_returned_as_unlocked(): void
    {
        [$owner, $tenant] = $this->provisionOwner();

        $categoryResponse = $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->postJson('/api/pos/categories', [
                'name' => 'Bebidas nuevas',
                'sort_order' => 1,
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.billing_enabled', true)
            ->assertJsonPath('data.locked_by_plan', false);

        $categoryId = $categoryResponse->json('data.id');

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->postJson('/api/pos/products', [
                'name' => 'Agua mineral',
                'category_id' => $categoryId,
                'price' => 30,
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.billing_enabled', true)
            ->assertJsonPath('data.locked_by_plan', false)
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.can_delete', true)
            ->assertJsonPath('data.can_use', true);
    }

    public function test_creating_beyond_plan_capacity_is_rejected_in_backend(): void
    {
        [$owner, $tenant] = $this->provisionOwner();

        app(CurrentTenant::class)->run(
            $tenant,
            function () use ($tenant): void {
                $category = $tenant->categories()->create([
                    'name' => 'Base',
                    'sort_order' => 1,
                    'is_active' => true,
                    'billing_enabled' => true,
                ]);

                for ($i = 1; $i <= 20; $i++) {
                    $tenant->products()->create([
                        'category_id' => $category->id,
                        'name' => 'Producto '.$i,
                        'description' => null,
                        'price' => '10.00',
                        'image_path' => null,
                        'sort_order' => $i,
                        'is_active' => true,
                        'billing_enabled' => true,
                    ]);
                }
            }
        );

        $category = $tenant->categories()->firstOrFail();

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->postJson('/api/pos/products', [
                'name' => 'Producto 21',
                'category_id' => $category->public_id,
                'price' => 10,
                'is_active' => true,
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'PLAN_LIMIT_EXCEEDED');
    }

    public function test_unlimited_catalog_capacity_is_not_converted_to_zero(): void
    {
        [$owner, $tenant] = $this->provisionOwner();
        app(CurrentTenant::class)->run($tenant, function () use ($tenant): void {
            $contract = app(\App\Services\Subscriptions\ContractService::class)->create(
                $tenant,
                app(\App\Services\Subscriptions\CatalogService::class)->price('restaurant'),
            );
            foreach (['base', 'extras'] as $role) {
                BillingSubscriptionLink::query()->create([
                    'plan_subscription_id' => $contract->id,
                    'role' => $role,
                    'stripe_subscription_id' => 'sub_unlimited_'.$role,
                    'status' => 'active',
                    'period_starts_at' => now(),
                    'period_ends_at' => now()->addMonth(),
                ]);
            }
            app(\App\Services\Subscriptions\ContractService::class)->activate($contract);
        });
        $this->actingAs($owner, 'web')->withHeader('X-Tenant-ID', $tenant->public_id)
            ->getJson('/api/pos/categories')->assertOk()
            ->assertJsonPath('meta.usage.limit', null)
            ->assertJsonPath('meta.usage.remaining', null)
            ->assertJsonPath('meta.usage.is_unlimited', true)
            ->assertJsonPath('meta.usage.limit_reached', false);
        $category = $this->postJson('/api/pos/categories', ['name' => 'Bebidas'])->assertCreated()->json('data.id');
        $this->postJson('/api/pos/products', ['name' => 'Agua', 'category_id' => $category, 'price' => 10, 'is_active' => true])->assertCreated();
        $usage = app(CurrentTenant::class)->run($tenant, function () use ($tenant) {
            app(CatalogAccessService::class)->syncTenant($tenant);
            return app(CatalogAccessService::class)->usage($tenant);
        });
        foreach (['categories', 'products'] as $resource) {
            $this->assertNull($usage[$resource]['limit']);
            $this->assertTrue($usage[$resource]['is_unlimited']);
            $this->assertFalse($usage[$resource]['limit_reached']);
            $this->assertSame(1, $usage[$resource]['used']);
        }
    }

    private function provisionOwner(): array
    {
        $this->seed([
            PlanSeeder::class,
            RoleSeeder::class,
        ]);

        $owner = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $plan = Plan::query()
            ->where('code', PlanCode::Free->value)
            ->sole();

        $tenant = app(CreateTenant::class)->handle(
            $owner,
            $plan,
            'Principal',
            'principal',
        );

        return [$owner, $tenant];
    }
}
