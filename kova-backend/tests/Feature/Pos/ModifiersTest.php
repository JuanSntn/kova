<?php

namespace Tests\Feature\Pos;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Models\Plan;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ModifiersTest extends TestCase
{
    use RefreshDatabase;

    private $tenant;

    private $owner;

    private $branch;

    private $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlanSeeder::class, RoleSeeder::class]);
        $this->owner = User::factory()->create(['email_verified_at' => now()]);
        $this->tenant = app(CreateTenant::class)->handle($this->owner, Plan::where('code', 'free')->sole(), 'Prueba', 'modifiers');
        $this->enableTenantFeature($this->tenant, 'allows_inventory');
        app(CurrentTenant::class)->set($this->tenant);
        $category = $this->tenant->categories()->create(['name' => 'Comida', 'is_active' => true]);
        $this->product = $this->tenant->products()->create(['name' => 'Hamburguesa', 'category_id' => $category->id, 'price' => '100.00', 'is_active' => true]);
        $this->branch = $this->tenant->branches()->firstOrFail();
        $this->actingAs($this->owner, 'web')->withHeaders(['X-Tenant-ID' => $this->tenant->public_id, 'X-Branch-ID' => $this->branch->public_id]);
    }

    private function group(string $kind = 'extras'): array
    {
        return $this->postJson('/api/pos/modifiers', ['name' => 'Opciones', 'kind' => $kind, 'is_active' => true, 'options' => [['name' => 'Grande'], ['name' => 'Extra queso']]])->assertOk()->json('data');
    }

    private function assign(array $g, array $effects = [])
    {
        return $this->putJson('/api/pos/products/'.$this->product->public_id.'/modifiers', ['groups' => [['id' => $g['id'], 'min' => $g['kind'] === 'size' ? 1 : 0, 'max' => $g['kind'] === 'size' ? 1 : 2, 'options' => array_map(fn ($o, $i) => ['id' => $o['id'], 'price' => '10.25', 'effect' => 'none', ...($effects[$i] ?? [])], $g['options'], [0, 1])]]]);
    }

    private function items(array $ids): array
    {
        return [['product_id' => $this->product->public_id, 'quantity' => 2, 'modifiers' => $ids]];
    }

    public function test_price_snapshots_distinct_lines_and_recovery(): void
    {
        $g = $this->group();
        $this->assign($g)->assertOk();
        $items = [...$this->items([$g['options'][0]['id']]), ...$this->items([$g['options'][1]['id']])];
        $quote = $this->postJson('/api/pos/checkout/quote', ['items' => $items])->assertOk()->assertJsonPath('data.total', '441.00')->json('data.quote_token');
        $payload = ['items' => $items, 'quote_token' => $quote, 'order_type' => 'counter', 'payment_method' => 'cash', 'amount_received' => '500.00'];
        $key = (string) Str::uuid();
        $sale = $this->postJson('/api/pos/orders', $payload, ['Idempotency-Key' => $key])->assertCreated()->assertJsonPath('data.change_amount', '59.00')->json('data');
        $g['name'] = 'Renamed';
        $g['options'][0]['name'] = 'Changed';
        $this->putJson('/api/pos/modifiers/'.$g['id'], $g)->assertOk();
        $this->postJson('/api/pos/orders', $payload, ['Idempotency-Key' => $key])->assertOk()->assertJsonPath('data.id', $sale['id'])->assertJsonPath('data.items.0.modifiers.0.option_name', 'Grande');
        $this->getJson('/api/pos/checkouts/'.$key)->assertOk()->assertJsonPath('data.folio', $sale['folio']);
        $this->getJson('/api/pos/orders/'.$sale['id'].'/ticket')->assertOk()
            ->assertJsonPath('data.sale.items.0.name', 'Hamburguesa')
            ->assertJsonPath('data.sale.items.0.base_unit_price', 100)
            ->assertJsonPath('data.sale.items.0.unit_price', 110.25)
            ->assertJsonPath('data.sale.items.0.modifiers.0.group_name', 'Opciones')
            ->assertJsonPath('data.sale.items.0.modifiers.0.option_name', 'Grande')
            ->assertJsonPath('data.sale.items.0.modifiers.0.price_adjustment', 10.25);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 2);
    }

    public function test_required_invalid_duplicate_and_forged_modifiers_are_rejected(): void
    {
        $g = $this->group('size');
        $this->assign($g)->assertOk();
        foreach ([[], [(string) Str::uuid()], [$g['options'][0]['id'], $g['options'][0]['id']], array_column($g['options'], 'id'), ['9']] as $ids) {
            $this->postJson('/api/pos/checkout/quote', ['items' => $this->items($ids)])->assertUnprocessable();
        }
        $items = $this->items([$g['options'][0]['id']]);
        $items[0]['price'] = 1;
        $this->postJson('/api/pos/checkout/quote', ['items' => $items])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_configuration_changed_requires_reconfirmation_even_if_total_is_same(): void
    {
        $g = $this->group();
        $this->assign($g)->assertOk();
        $items = $this->items([$g['options'][0]['id']]);
        $token = $this->postJson('/api/pos/checkout/quote', ['items' => $items])->assertOk()->json('data.quote_token');
        $g['options'][0]['name'] = 'Otro tamaño';
        $this->putJson('/api/pos/modifiers/'.$g['id'], $g)->assertOk();
        $this->postJson('/api/pos/orders', ['items' => $items, 'quote_token' => $token, 'order_type' => 'counter', 'payment_method' => 'card'], ['Idempotency-Key' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'POS_QUOTE_CHANGED');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_inventory_replacement_plus_extra_consumes_exactly_once(): void
    {
        $s = $this->postJson('/api/inventory/supplies', ['id' => (string) Str::uuid(), 'name' => 'Queso', 'category' => 'Cocina', 'unit' => 'g', 'produced' => false, 'presentations' => []])->assertCreated()->json('data.id');
        $recipe = $this->postJson('/api/inventory/recipes', ['name' => 'Grande', 'type' => 'recipe', 'yield_quantity' => '1', 'yield_unit' => 'pz', 'components' => [['type' => 'supply', 'id' => $s, 'quantity' => '30', 'unit' => 'g']]])->assertOk()->json('data.id');
        $g = $this->group('size');
        $this->assign($g, [['effect' => 'replace', 'target_type' => 'recipe', 'target_id' => $recipe, 'quantity' => '1']])->assertOk();
        $items = $this->items([$g['options'][0]['id']]);
        $token = $this->postJson('/api/pos/checkout/quote', ['items' => $items])->assertOk()->json('data.quote_token');
        $payload = ['items' => $items, 'quote_token' => $token, 'order_type' => 'counter', 'payment_method' => 'card'];
        $key = (string) Str::uuid();
        $this->postJson('/api/pos/orders', $payload, ['Idempotency-Key' => $key])->assertCreated();
        $this->postJson('/api/pos/orders', $payload, ['Idempotency-Key' => $key])->assertOk();
        $this->assertDatabaseCount('inventory_operations', 1);
        $this->assertDatabaseHas('inventory_balances', ['quantity' => '-60.000000']);
    }

    public function test_cross_tenant_product_group_option_and_branch_are_rejected(): void
    {
        $g = $this->group();
        $this->assign($g)->assertOk();
        $other = app(CreateTenant::class)->handle($this->owner, Plan::where('code', 'free')->sole(), 'Otro', 'other-modifiers');
        $this->withHeader('X-Tenant-ID', $other->public_id);
        $this->getJson('/api/pos/modifiers')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/pos/products/'.$this->product->public_id.'/modifiers')->assertNotFound();
        $this->putJson('/api/pos/modifiers/'.$g['id'], $g)->assertNotFound();
        $this->postJson('/api/pos/checkout/quote', ['items' => $this->items([$g['options'][0]['id']])])->assertNotFound();
        $this->getJson('/api/pos/products/'.$this->product->id.'/modifiers')->assertNotFound();
        $this->withHeader('X-Tenant-ID', $this->tenant->public_id);
        $alien = $this->postJson('/api/pos/modifiers', ['name' => 'Injected', 'kind' => 'extras', 'is_active' => true, 'options' => [['id' => $g['options'][0]['id'], 'name' => 'Wrong']]]);
        $alien->assertUnprocessable();
    }

    public function test_user_without_catalog_permissions_cannot_read_or_mutate_groups(): void
    {
        $g = $this->group();
        $user = User::factory()->create(['email_verified_at' => now()]);
        DB::table('memberships')->insert(['public_id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        app('auth')->forgetGuards();
        $this->actingAs($user, 'web');
        $this->getJson('/api/pos/modifiers')->assertForbidden();
        $this->putJson('/api/pos/modifiers/'.$g['id'],$g)->assertForbidden();
        $this->getJson('/api/pos/products/'.$this->product->public_id.'/modifiers')->assertForbidden();
    }

    public function test_extras_and_omissions_adjust_base_recipe_without_creating_stock(): void
    {
        $s = $this->postJson('/api/inventory/supplies', ['id' => (string) Str::uuid(), 'name' => 'Cebolla', 'category' => 'Cocina', 'unit' => 'g', 'produced' => false, 'presentations' => []])->assertCreated()->json('data.id');
        $this->putJson('/api/inventory/products/'.$this->product->public_id, ['type' => 'supply', 'target_id' => $s, 'quantity' => '20'])->assertOk();
        $g = $this->group();
        $this->assign($g, [
            ['effect' => 'add', 'target_type' => 'supply', 'target_id' => $s, 'quantity' => '10'],
            ['effect' => 'remove', 'target_type' => 'supply', 'target_id' => $s, 'quantity' => '20'],
        ])->assertOk();
        $this->postJson('/api/pos/checkout/quote', ['items' => $this->items([$g['options'][0]['id']])])->assertOk()->assertJsonPath('data.lines.0.inventory_snapshot.0.quantity', '30.000000');
        $this->postJson('/api/pos/checkout/quote', ['items' => $this->items([$g['options'][1]['id']])])->assertOk()->assertJsonPath('data.lines.0.inventory_snapshot.0.quantity', '0.000000');
        $this->assign($g, [[], ['effect' => 'remove', 'target_type' => 'supply', 'target_id' => $s, 'quantity' => '21']])->assertOk();
        $this->postJson('/api/pos/checkout/quote', ['items' => $this->items([$g['options'][1]['id']])])->assertUnprocessable();
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_ingredient_swap_removes_the_base_supply_and_adds_the_selected_supply(): void
    {
        $regular = $this->postJson('/api/inventory/supplies', ['id' => (string) Str::uuid(), 'name' => 'Pan clásico', 'category' => 'Cocina', 'unit' => 'pz', 'produced' => false, 'presentations' => []])->assertCreated()->json('data.id');
        $garlic = $this->postJson('/api/inventory/supplies', ['id' => (string) Str::uuid(), 'name' => 'Pan de ajo', 'category' => 'Cocina', 'unit' => 'pz', 'produced' => false, 'presentations' => []])->assertCreated()->json('data.id');
        $this->putJson('/api/inventory/products/'.$this->product->public_id, ['type' => 'supply', 'target_id' => $regular, 'quantity' => '1'])->assertOk();
        $group = $this->group();

        $swap = [
            'effect' => 'swap',
            'source_type' => 'supply',
            'source_id' => $regular,
            'source_quantity' => '1',
            'target_type' => 'supply',
            'target_id' => $garlic,
            'quantity' => '1',
        ];
        $this->assign($group, [$swap])->assertOk();

        $quote = $this->postJson('/api/pos/checkout/quote', ['items' => $this->items([$group['options'][0]['id']])])
            ->assertOk()
            ->assertJsonPath('data.lines.0.inventory_snapshot.0.name', 'Pan clásico')
            ->assertJsonPath('data.lines.0.inventory_snapshot.0.quantity', '0.000000')
            ->assertJsonPath('data.lines.0.inventory_snapshot.1.name', 'Pan de ajo')
            ->assertJsonPath('data.lines.0.inventory_snapshot.1.quantity', '1.000000')
            ->json('data.quote_token');

        $this->postJson('/api/pos/orders', ['items' => $this->items([$group['options'][0]['id']]), 'quote_token' => $quote, 'order_type' => 'counter', 'payment_method' => 'card'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $regularId = DB::table('inventory_supplies')->where('tenant_id', $this->tenant->id)->where('public_id', $regular)->value('id');
        $garlicId = DB::table('inventory_supplies')->where('tenant_id', $this->tenant->id)->where('public_id', $garlic)->value('id');
        $this->assertDatabaseHas('inventory_balances', ['supply_id' => $regularId, 'quantity' => '0.000000']);
        $this->assertDatabaseHas('inventory_balances', ['supply_id' => $garlicId, 'quantity' => '-2.000000']);

        $this->assign($group, [[...$swap, 'source_quantity' => '2']])->assertOk();
        $this->postJson('/api/pos/checkout/quote', ['items' => $this->items([$group['options'][0]['id']])])->assertUnprocessable();
        $this->assertDatabaseCount('inventory_operations', 1);
    }

    public function test_foreign_inventory_target_and_unassigned_options_are_rejected(): void
    {
        $g = $this->group();
        $other = $this->group();
        $this->assign($g)->assertOk();
        $this->postJson('/api/pos/checkout/quote', ['items' => $this->items([$other['options'][0]['id']])])->assertUnprocessable();
        $this->assign($g, [['effect' => 'add', 'target_type' => 'supply', 'target_id' => (string) Str::uuid(), 'quantity' => '1']])->assertUnprocessable();
        $this->assign($g, [['effect' => 'swap', 'source_type' => 'supply', 'source_id' => (string) Str::uuid(), 'source_quantity' => '1', 'target_type' => 'supply', 'target_id' => (string) Str::uuid(), 'quantity' => '1']])->assertUnprocessable();
        $this->assign($g, [['price' => '-1']])->assertUnprocessable();
        $this->assertDatabaseCount('menu_product_modifiers', 1);
    }
}
