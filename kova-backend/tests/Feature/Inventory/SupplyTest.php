<?php

namespace Tests\Feature\Inventory;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupplyTest extends TestCase
{
    use RefreshDatabase;

    private function provision(bool $enabled = true): array
    {
        $this->seed([PlanSeeder::class, RoleSeeder::class]);
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $tenant = app(CreateTenant::class)->handle($owner, Plan::where('code', 'free')->sole(), 'Principal', 'principal');
        if ($enabled) {
            $this->enableTenantFeature($tenant, 'allows_inventory');
        }
        $this->actingAs($owner, 'web')->withHeader('X-Tenant-ID', $tenant->public_id);

        return [$owner, $tenant];
    }

    private function payload(): array
    {
        return ['id' => (string) Str::uuid(), 'name' => 'Aceite', 'category' => 'Cocina', 'unit' => 'ml', 'minimum' => '100', 'ideal' => '5000', 'produced' => false,
            'presentations' => [['id' => (string) Str::uuid(), 'name' => 'Garrafa', 'quantity' => '5', 'unit' => 'L', 'supplier' => 'Proveedor', 'supplierCode' => 'ACE-5', 'price' => '150.50', 'tax' => null]]];
    }

    public function test_create_list_edit_and_stale_version(): void
    {
        $this->provision();
        $data = $this->payload();
        $this->postJson('/api/inventory/supplies', $data)->assertCreated()->assertJsonPath('data.presentations.0.baseQuantity', '5000')->assertJsonPath('data.version', 1);
        $this->getJson('/api/inventory/supplies')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $data['id'])->assertJsonMissingPath('data.0.tenant_id');
        $id = $data['id'];
        unset($data['id']);
        $data['version'] = 1;
        $data['name'] = 'Aceite vegetal';
        $this->putJson('/api/inventory/supplies/'.$id, $data)->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson('/api/inventory/supplies/'.$id, $data)->assertConflict();
        $this->assertDatabaseCount('inventory_supplies', 1);
        $this->assertDatabaseCount('inventory_presentations', 1);
    }

    public function test_rejects_incompatible_conversion_and_invalid_levels_without_partial_data(): void
    {
        $this->provision();
        $data = $this->payload();
        $data['presentations'][0]['unit'] = 'kg';
        $this->postJson('/api/inventory/supplies', $data)->assertUnprocessable()->assertJsonValidationErrors('presentations.0.unit');
        $data = $this->payload();
        $data['ideal'] = '1';
        $this->postJson('/api/inventory/supplies', $data)->assertUnprocessable()->assertJsonValidationErrors('ideal');
        $this->assertDatabaseCount('inventory_supplies', 0);
    }

    public function test_feature_and_permission_are_authoritative(): void
    {
        [$owner, $tenant] = $this->provision(false);
        $this->postJson('/api/inventory/supplies', $this->payload())->assertForbidden();
        $this->enableTenantFeature($tenant, 'allows_inventory');
        setPermissionsTeamId($tenant->id);
        $owner->syncRoles(['cashier']);
        $this->postJson('/api/inventory/supplies', $this->payload())->assertForbidden();
        $this->getJson('/api/inventory/supplies')->assertForbidden();
        $this->assertDatabaseCount('inventory_supplies', 0);
    }

    public function test_rejects_internal_ids_and_repeated_create(): void
    {
        $this->provision();
        $data = $this->payload();
        $this->postJson('/api/inventory/supplies', $data + ['tenant_id' => 1])->assertUnprocessable();
        $this->putJson('/api/inventory/supplies/1', $data)->assertNotFound();
        $this->postJson('/api/inventory/supplies', $data)->assertCreated();
        $this->postJson('/api/inventory/supplies', $data)->assertConflict();
        $this->assertDatabaseCount('inventory_supplies', 1);
    }

    public function test_pieces_produced_supplies_and_suspended_contract(): void
    {
        [$owner, $tenant] = $this->provision();
        $data = $this->payload();
        $data['unit'] = 'pz';
        $data['presentations'][0]['unit'] = 'pz';
        $data['presentations'][0]['quantity'] = '24';
        $this->postJson('/api/inventory/supplies', $data)->assertCreated()->assertJsonPath('data.presentations.0.baseQuantity', '24');
        $data = $this->payload();
        $data['produced'] = true;
        $data['presentations'] = [];
        $this->postJson('/api/inventory/supplies', $data)->assertCreated()->assertJsonPath('data.produced', true);
        DB::table('plan_subscriptions')->where('current_tenant_id', $tenant->id)->update(['status' => 'suspended']);
        $this->postJson('/api/inventory/supplies', $this->payload())->assertStatus(409);
        $this->assertDatabaseCount('inventory_supplies', 2);
    }

    public function test_cross_tenant_resource_and_tax_are_rejected(): void
    {
        [$owner, $tenant] = $this->provision();
        $data = $this->payload();
        $this->postJson('/api/inventory/supplies', $data)->assertCreated();
        $other = app(CreateTenant::class)->handle($owner, Plan::where('code', 'free')->sole(), 'Otro', 'otro');
        $this->withHeader('X-Tenant-ID', $other->public_id);
        $id = $data['id'];
        unset($data['id']);
        $data['version'] = 1;
        $this->putJson('/api/inventory/supplies/'.$id, $data)->assertNotFound();
        $this->getJson('/api/inventory/supplies')->assertOk()->assertJsonPath('meta.total', 0);
        $tax = DB::table('taxes')->where('tenant_id', $tenant->id)->value('public_id');
        if (! $tax) {
            $tax = (string) Str::uuid();
            DB::table('taxes')->insert(['tenant_id' => $tenant->id, 'public_id' => $tax, 'name' => 'IVA', 'code' => 'iva', 'factor_type' => 'Tasa', 'rate' => 16, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $data = $this->payload();
        $data['presentations'][0]['tax'] = $tax;
        $this->postJson('/api/inventory/supplies', $data)->assertUnprocessable()->assertJsonValidationErrors('presentations.0.tax');
    }
}
