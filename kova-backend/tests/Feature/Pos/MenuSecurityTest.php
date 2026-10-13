<?php

namespace Tests\Feature\Pos;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Enums\PlanCode;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MenuSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    private Branch $branch;

    private Category $category;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PlanSeeder::class, RoleSeeder::class]);
        $this->owner = User::factory()->create(['email_verified_at' => now()]);
        $this->tenant = app(CreateTenant::class)->handle(
            $this->owner,
            Plan::query()->where('code', PlanCode::Free->value)->sole(),
            'Menú seguro',
            'menu-seguro',
        );

        [$this->category, $this->product, $this->branch] = app(CurrentTenant::class)->run(
            $this->tenant,
            function (): array {
                $category = $this->tenant->categories()->create([
                    'name' => 'Bebidas',
                    'sort_order' => 1,
                    'is_active' => true,
                    'billing_enabled' => true,
                ]);
                $product = $this->tenant->products()->create([
                    'category_id' => $category->id,
                    'name' => 'Agua mineral',
                    'price' => '35.00',
                    'sort_order' => 1,
                    'is_active' => true,
                    'billing_enabled' => true,
                ]);

                return [$category, $product, $this->tenant->branches()->sole()];
            },
        );
    }

    public function test_catalog_routes_use_public_uuids_and_hide_resources_from_another_tenant(): void
    {
        $other = app(CreateTenant::class)->handle(
            $this->owner,
            Plan::query()->where('code', PlanCode::Free->value)->sole(),
            'Otro negocio',
            'otro-negocio-menu',
        );
        [$foreignCategory, $foreignProduct] = app(CurrentTenant::class)->run(
            $other,
            function () use ($other): array {
                $category = $other->categories()->create(['name' => 'Privada', 'is_active' => true]);
                $product = $other->products()->create(['name' => 'Producto privado', 'category_id' => $category->id, 'price' => '10.00', 'is_active' => true]);

                return [$category, $product];
            },
        );

        $this->asOwner()
            ->putJson('/api/pos/categories/'.$foreignCategory->public_id, ['name' => 'Intento'])
            ->assertNotFound();
        $this->asOwner()
            ->deleteJson('/api/pos/categories/'.$foreignCategory->public_id)
            ->assertNotFound();
        $this->asOwner()
            ->putJson('/api/pos/products/'.$foreignProduct->public_id, ['name' => 'Intento'])
            ->assertNotFound();
        $this->asOwner()
            ->deleteJson('/api/pos/products/'.$foreignProduct->public_id)
            ->assertNotFound();

        $this->asOwner()
            ->postJson('/api/pos/products', [
                'name' => 'No puede usar categoría ajena',
                'category_id' => $foreignCategory->public_id,
                'price' => '25.00',
                'is_active' => true,
            ])
            ->assertNotFound();

        $this->asOwner()
            ->putJson('/api/pos/categories/1', ['name' => 'ID interno'])
            ->assertNotFound();
        $this->asOwner()
            ->putJson('/api/pos/products/1', ['name' => 'ID interno'])
            ->assertNotFound();
    }

    public function test_client_cannot_submit_tenant_branch_or_plan_fields_for_menu_records(): void
    {
        $this->asOwner()
            ->postJson('/api/pos/categories', [
                'name' => 'Categoría inyectada',
                'tenant_id' => 999,
                'branch_id' => (string) Str::uuid(),
                'billing_enabled' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tenant_id', 'branch_id', 'billing_enabled']);

        $this->asOwner()
            ->postJson('/api/pos/products', [
                'name' => 'Producto inyectado',
                'category_id' => $this->category->public_id,
                'price' => '20.00',
                'is_active' => true,
                'tenant_id' => 999,
                'branch_id' => (string) Str::uuid(),
                'billing_enabled' => false,
                'can_edit' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tenant_id', 'branch_id', 'billing_enabled', 'can_edit']);

        $this->asOwner()
            ->postJson('/api/pos/modifiers', [
                'name' => 'Grupo inyectado',
                'kind' => 'extras',
                'is_active' => true,
                'options' => [['name' => 'Opción']],
                'tenant_id' => 999,
                'branch_id' => (string) Str::uuid(),
                'user_id' => 999,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tenant_id', 'branch_id', 'user_id']);

        $this->asOwner()
            ->postJson('/api/pos/categories', ['name' => 'Categoría válida', 'is_active' => true])
            ->assertCreated()
            ->assertJsonPath('data.billing_enabled', true);

        $this->assertSame(2, $this->tenant->categories()->count());
        $this->assertSame(1, $this->tenant->products()->count());
    }

    public function test_user_without_catalog_permissions_cannot_read_any_menu_tab(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        DB::table('memberships')->insert([
            'public_id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app('auth')->forgetGuards();
        $this->actingAs($user, 'web')->withHeader('X-Tenant-ID', $this->tenant->public_id)
            ->getJson('/api/pos/categories')->assertForbidden();
        $this->actingAs($user, 'web')->withHeader('X-Tenant-ID', $this->tenant->public_id)
            ->getJson('/api/pos/products')->assertForbidden();
        $this->actingAs($user, 'web')->withHeader('X-Tenant-ID', $this->tenant->public_id)
            ->getJson('/api/pos/modifiers')->assertForbidden();
    }

    public function test_free_catalog_keeps_menu_crud_but_enforces_product_and_category_limits(): void
    {
        for ($i = 2; $i <= 5; $i++) {
            $this->asOwner()
                ->postJson('/api/pos/categories', ['name' => 'Categoría '.$i, 'is_active' => true])
                ->assertCreated();
        }

        $this->asOwner()
            ->postJson('/api/pos/categories', ['name' => 'Categoría 6', 'is_active' => true])
            ->assertConflict()
            ->assertJsonPath('code', 'PLAN_LIMIT_EXCEEDED');

        for ($i = 2; $i <= 20; $i++) {
            $this->asOwner()
                ->postJson('/api/pos/products', [
                    'name' => 'Producto '.$i,
                    'category_id' => $this->category->public_id,
                    'price' => '10.00',
                    'is_active' => true,
                ])
                ->assertCreated();
        }

        $this->asOwner()
            ->postJson('/api/pos/products', [
                'name' => 'Producto 21',
                'category_id' => $this->category->public_id,
                'price' => '10.00',
                'is_active' => true,
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'PLAN_LIMIT_EXCEEDED');
    }

    public function test_modifier_inventory_effects_require_the_inventory_feature_even_for_an_owner(): void
    {
        $group = $this->asOwner()
            ->postJson('/api/pos/modifiers', [
                'name' => 'Extras',
                'kind' => 'extras',
                'is_active' => true,
                'options' => [['name' => 'Queso extra']],
            ])
            ->assertOk()
            ->json('data');

        $this->asOwner()
            ->putJson('/api/pos/products/'.$this->product->public_id.'/modifiers', [
                'groups' => [[
                    'id' => $group['id'],
                    'min' => 0,
                    'max' => 1,
                    'options' => [[
                        'id' => $group['options'][0]['id'],
                        'price' => '0.00',
                        'effect' => 'add',
                        'target_type' => 'supply',
                        'target_id' => (string) Str::uuid(),
                        'quantity' => '1',
                    ]],
                ]],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('menu_product_modifiers', 0);
    }

    public function test_operational_catalog_obeys_the_selected_branch_availability_and_price(): void
    {
        $otherBranch = app(CurrentTenant::class)->run(
            $this->tenant,
            fn () => $this->tenant->branches()->create([
                'code' => 'SUR',
                'name' => 'Sucursal Sur',
                'timezone' => 'America/Mexico_City',
                'is_active' => true,
                'billing_enabled' => true,
            ]),
        );

        $this->asOwner()
            ->putJson('/api/settings/branches/'.$this->branch->public_id.'/products/'.$this->product->public_id, [
                'is_available' => false,
                'price_override' => null,
            ])
            ->assertOk();
        $this->asOwner()
            ->putJson('/api/settings/branches/'.$otherBranch->public_id.'/products/'.$this->product->public_id, [
                'is_available' => true,
                'price_override' => '42.00',
            ])
            ->assertOk();

        $this->asOwner($this->branch)
            ->getJson('/api/pos/catalog/products')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->asOwner($otherBranch)
            ->getJson('/api/pos/catalog/products')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->product->public_id)
            ->assertJsonPath('data.0.price', '42.00');
    }

    private function asOwner(?Branch $branch = null): static
    {
        return $this->actingAs($this->owner, 'web')->withHeaders([
            'X-Tenant-ID' => $this->tenant->public_id,
            'X-Branch-ID' => ($branch ?? $this->branch)->public_id,
        ]);
    }
}
