<?php

namespace Tests\Feature\Inventory;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Enums\MembershipStatus;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\Plan;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventorySecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private $tenant;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlanSeeder::class, RoleSeeder::class]);
        $this->owner = User::factory()->create(['email_verified_at' => now()]);
        $this->tenant = app(CreateTenant::class)->handle(
            $this->owner,
            Plan::query()->where('code', 'free')->sole(),
            'Inventario seguro',
            'inventario-seguro',
        );
        $this->branch = Branch::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->sole();
    }

    private function inventoryEnabled(): void
    {
        $this->enableTenantFeature($this->tenant, 'allows_inventory');
    }

    private function requestAs(User $user, $tenant = null, ?Branch $branch = null): static
    {
        $tenant ??= $this->tenant;

        return $this->actingAs($user, 'web')->withHeaders([
            'X-Tenant-ID' => $tenant->public_id,
            ...( $branch ? ['X-Branch-ID' => $branch->public_id] : []),
        ]);
    }

    private function supply(string $name = 'Harina'): array
    {
        return $this->postJson('/api/inventory/supplies', [
            'id' => (string) Str::uuid(),
            'name' => $name,
            'category' => 'Cocina',
            'unit' => 'g',
            'produced' => false,
            'presentations' => [],
        ])->assertCreated()->json('data');
    }

    private function recipe(string $name, string $supplyId): array
    {
        return $this->postJson('/api/inventory/recipes', [
            'name' => $name,
            'type' => 'recipe',
            'yield_quantity' => '1',
            'yield_unit' => 'pz',
            'components' => [[
                'type' => 'supply',
                'id' => $supplyId,
                'quantity' => '25',
                'unit' => 'g',
            ]],
        ])->assertOk()->json('data');
    }

    private function createProduct(string $name): array
    {
        return app(CurrentTenant::class)->run($this->tenant, function () use ($name): array {
            $category = $this->tenant->categories()->firstOrCreate(['name' => 'Platillos'], ['is_active' => true]);
            $product = $this->tenant->products()->create([
                'name' => $name,
                'category_id' => $category->id,
                'price' => '100.00',
                'is_active' => true,
            ]);

            return ['id' => $product->public_id, 'category_id' => $category->public_id];
        });
    }

    public function test_free_plan_denies_every_inventory_tab_in_the_backend(): void
    {
        $this->requestAs($this->owner, $this->tenant, $this->branch);
        $uuid = (string) Str::uuid();

        $this->getJson('/api/inventory/catalog')->assertForbidden();
        $this->getJson('/api/inventory/supplies')->assertForbidden();
        $this->postJson('/api/inventory/categories', ['name' => 'Cocina'])->assertForbidden();
        $this->postJson('/api/inventory/recipes', ['name' => 'Salsa'])->assertForbidden();
        $this->getJson('/api/inventory/products')->assertForbidden();
        $this->getJson('/api/inventory/products/'.$uuid)->assertForbidden();
        $this->putJson('/api/inventory/products/'.$uuid, ['type' => 'none', 'quantity' => '1'])->assertForbidden();
        $this->getJson('/api/inventory/balances')->assertForbidden();
        $this->getJson('/api/inventory/movements')->assertForbidden();
        $this->postJson('/api/inventory/operations', [
            'type' => 'IN',
            'reason' => 'No debe operar',
            'supply_id' => $uuid,
            'quantity' => '1',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
        $this->getJson('/api/inventory/excel')->assertForbidden();
        $this->postJson('/api/inventory/imports')->assertForbidden();
        $this->postJson('/api/inventory/imports/'.$uuid.'/commit')->assertForbidden();

        $this->assertDatabaseCount('inventory_supplies', 0);
        $this->assertDatabaseCount('inventory_operations', 0);
    }

    public function test_catalog_tabs_are_tenant_scoped_and_reject_client_scope_fields(): void
    {
        $this->inventoryEnabled();
        $this->requestAs($this->owner, $this->tenant, $this->branch);
        $supply = $this->supply();
        $recipe = $this->recipe('Pan', $supply['id']);
        $product = $this->createProduct('Pan de la casa');

        $other = app(CreateTenant::class)->handle(
            $this->owner,
            Plan::query()->where('code', 'free')->sole(),
            'Otro negocio',
            'otro-negocio-inventario',
        );
        $this->enableTenantFeature($other, 'allows_inventory');
        $otherBranch = Branch::withoutGlobalScopes()->where('tenant_id', $other->id)->sole();
        $this->requestAs($this->owner, $other, $otherBranch);
        $otherSupply = $this->supply('Insumo privado');
        $otherRecipe = $this->recipe('Receta privada', $otherSupply['id']);
        $otherProduct = $this->createOtherProduct($other, 'Producto privado');
        $otherCategory = DB::table('inventory_categories')->where('tenant_id', $other->id)->value('public_id');

        $this->requestAs($this->owner, $this->tenant, $this->branch);
        $this->getJson('/api/inventory/catalog')
            ->assertOk()
            ->assertJsonMissing(['id' => $otherSupply['id']])
            ->assertJsonMissing(['id' => $otherRecipe['id']]);
        $this->getJson('/api/inventory/supplies?search=privado')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/inventory/products?search=privado')->assertOk()->assertJsonPath('meta.total', 0);
        $this->putJson('/api/inventory/categories/'.$otherCategory, ['name' => 'Manipulada'])->assertNotFound();
        $this->putJson('/api/inventory/supplies/'.$otherSupply['id'], $this->supplyUpdatePayload())->assertNotFound();
        $this->putJson('/api/inventory/recipes/'.$otherRecipe['id'], $this->recipeUpdatePayload($supply['id']))->assertNotFound();
        $this->putJson('/api/inventory/products/'.$otherProduct, ['type' => 'none', 'quantity' => '1'])->assertNotFound();
        $this->postJson('/api/inventory/recipes', $this->recipePayload('Cruza tenant', $otherSupply['id']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('components.0');
        $this->putJson('/api/inventory/products/'.$product['id'], ['type' => 'recipe', 'target_id' => $otherRecipe['id'], 'quantity' => '1'])
            ->assertUnprocessable();
        $this->postJson('/api/inventory/operations', [
            'type' => 'IN',
            'reason' => 'Intento cruzado',
            'supply_id' => $otherSupply['id'],
            'quantity' => '1',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertNotFound();

        $this->postJson('/api/inventory/categories', [
            'name' => 'No confiar',
            'tenant_id' => $other->id,
            'branch_id' => $otherBranch->public_id,
            'user_id' => $this->owner->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['tenant_id', 'branch_id', 'user_id']);
        $this->postJson('/api/inventory/recipes', $this->recipePayload('Sin alcance', $supply['id']) + [
            'tenant_id' => $other->id,
            'branch_id' => $otherBranch->public_id,
            'user_id' => $this->owner->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['tenant_id', 'branch_id', 'user_id']);
        $this->putJson('/api/inventory/products/'.$product['id'], [
            'type' => 'none',
            'quantity' => '1',
            'tenant_id' => $other->id,
            'branch_id' => $otherBranch->public_id,
            'user_id' => $this->owner->id,
            'product_id' => $otherProduct,
        ])->assertUnprocessable()->assertJsonValidationErrors(['tenant_id', 'branch_id', 'user_id', 'product_id']);
    }

    public function test_operational_tabs_are_isolated_by_branch_and_staff_assignment(): void
    {
        $this->inventoryEnabled();
        $this->requestAs($this->owner, $this->tenant, $this->branch);
        $supply = $this->supply();
        $second = app(CurrentTenant::class)->run($this->tenant, fn () => Branch::query()->create([
            'code' => 'DOS',
            'name' => 'Sucursal dos',
            'timezone' => 'America/Mexico_City',
            'is_active' => true,
            'billing_enabled' => true,
        ]));
        $this->operation('IN', $supply['id'], '8')->assertOk();

        $this->requestAs($this->owner, $this->tenant, $second);
        $this->getJson('/api/inventory/balances')->assertOk()->assertJsonPath('data.0.quantity', '0.000000');
        $this->getJson('/api/inventory/movements')->assertOk()->assertJsonCount(0, 'data');

        $this->requestAs($this->owner, $this->tenant, $this->branch)
            ->postJson('/api/inventory/operations', [
                'type' => 'IN', 'reason' => 'Sucursal no asignada', 'supply_id' => $supply['id'], 'quantity' => '1',
            ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()
            ->assertJsonPath('data.branch_id', $this->branch->public_id);
        $this->requestAs($this->owner, $this->tenant)->withHeader('X-Branch-ID', '1')->getJson('/api/inventory/balances')->assertNotFound();
        $this->requestAs($this->owner, $this->tenant, $this->branch)
            ->postJson('/api/inventory/operations', [
                'type' => 'IN',
                'reason' => 'No se toma del cuerpo',
                'supply_id' => $supply['id'],
                'quantity' => '1',
                'branch_id' => $second->public_id,
            ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');
    }

    public function test_staff_cannot_read_or_operate_an_unassigned_branch(): void
    {
        $this->inventoryEnabled();
        $supplyId = (string) Str::uuid();
        DB::table('inventory_supplies')->insert([
            'tenant_id' => $this->tenant->id,
            'public_id' => $supplyId,
            'name' => 'Harina',
            'category' => 'Cocina',
            'base_unit' => 'g',
            'produced' => false,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $second = app(CurrentTenant::class)->run($this->tenant, fn () => Branch::query()->create([
            'code' => 'DOS',
            'name' => 'Sucursal dos',
            'timezone' => 'America/Mexico_City',
            'is_active' => true,
            'billing_enabled' => true,
        ]));
        $staff = User::factory()->create(['email_verified_at' => now()]);
        $membership = Membership::query()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $staff->id,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $membership->branches()->attach($this->branch->id);
        setPermissionsTeamId($this->tenant->id);
        $staff->givePermissionTo(['inventory.view', 'inventory.manage']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->requestAs($staff, $this->tenant, $this->branch)->getJson('/api/inventory/balances')->assertOk();
        $this->requestAs($staff, $this->tenant, $second)->getJson('/api/inventory/balances')->assertForbidden();
        $this->requestAs($staff, $this->tenant, $second)
            ->postJson('/api/inventory/operations', [
                'type' => 'IN', 'reason' => 'Sucursal no asignada', 'supply_id' => $supplyId, 'quantity' => '1',
            ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertForbidden();
        $this->assertDatabaseCount('inventory_operations', 0);
    }

    public function test_excel_import_commit_is_scoped_to_the_current_tenant_and_author(): void
    {
        $this->inventoryEnabled();
        $import = (string) Str::uuid();
        DB::table('inventory_imports')->insert([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'public_id' => $import,
            'file_hash' => hash('sha256', 'private-import'),
            'payload' => '[]',
            'rows_count' => 0,
            'created_at' => now(),
        ]);

        $staff = User::factory()->create(['email_verified_at' => now()]);
        Membership::query()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $staff->id,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        setPermissionsTeamId($this->tenant->id);
        $staff->givePermissionTo(['inventory.view', 'inventory.manage']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->requestAs($staff, $this->tenant)->postJson('/api/inventory/imports/'.$import.'/commit')->assertNotFound();

        $other = app(CreateTenant::class)->handle(
            $this->owner,
            Plan::query()->where('code', 'free')->sole(),
            'Otro negocio',
            'otro-negocio-excel',
        );
        $this->enableTenantFeature($other, 'allows_inventory');
        $this->requestAs($this->owner, $other)->postJson('/api/inventory/imports/'.$import.'/commit')->assertNotFound();
        $this->assertDatabaseHas('inventory_imports', ['public_id' => $import, 'tenant_id' => $this->tenant->id, 'committed_at' => null]);
    }

    private function operation(string $type, string $supplyId, string $quantity)
    {
        return $this->postJson('/api/inventory/operations', [
            'type' => $type,
            'reason' => 'Prueba de seguridad',
            'supply_id' => $supplyId,
            'quantity' => $quantity,
        ], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function recipePayload(string $name, string $supplyId): array
    {
        return [
            'name' => $name,
            'type' => 'recipe',
            'yield_quantity' => '1',
            'yield_unit' => 'pz',
            'components' => [['type' => 'supply', 'id' => $supplyId, 'quantity' => '1', 'unit' => 'g']],
        ];
    }

    private function recipeUpdatePayload(string $supplyId): array
    {
        return $this->recipePayload('Receta actualizada', $supplyId) + ['version' => 1];
    }

    private function supplyUpdatePayload(): array
    {
        return [
            'name' => 'Insumo actualizado',
            'category' => 'Cocina',
            'unit' => 'g',
            'produced' => false,
            'presentations' => [],
            'version' => 1,
        ];
    }

    private function createOtherProduct($tenant, string $name): string
    {
        return app(CurrentTenant::class)->run($tenant, function () use ($tenant, $name): string {
            $category = $tenant->categories()->create(['name' => 'Privada', 'is_active' => true]);

            return $tenant->products()->create([
                'name' => $name,
                'category_id' => $category->id,
                'price' => '40.00',
                'is_active' => true,
            ])->public_id;
        });
    }
}
