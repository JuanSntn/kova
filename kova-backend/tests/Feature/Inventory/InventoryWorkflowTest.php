<?php

namespace Tests\Feature\Inventory;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Models\Plan;
use App\Models\User;
use App\Models\Order;
use App\Services\Inventory\InventoryExcelService;
use App\Services\Inventory\InventoryLedgerService;
use App\Services\Pos\TenantWriteTransaction;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;
use Tests\TestCase;

class InventoryWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\QuotesPosCheckout;

    private $tenant;
    private $owner;
    private $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlanSeeder::class, RoleSeeder::class]);
        $this->owner = User::factory()->create(['email_verified_at' => now()]);
        $this->tenant = app(CreateTenant::class)->handle($this->owner, Plan::where('code', 'free')->sole(), 'Inventario', 'inventario');
        $this->enableTenantFeature($this->tenant, 'allows_inventory');
        $this->branch = \App\Models\Branch::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->actingAs($this->owner, 'web')->withHeader('X-Tenant-ID', $this->tenant->public_id)->withHeader('X-Branch-ID', $this->branch->public_id);
    }

    private function supply(string $name, bool $produced = false, string $unit = 'g'): array
    {
        return $this->postJson('/api/inventory/supplies', ['id' => (string)Str::uuid(), 'name' => $name, 'category' => 'Cocina', 'unit' => $unit, 'produced' => $produced, 'presentations' => []])->assertCreated()->json('data');
    }

    private function recipe(string $name, string $type, array $components, ?string $output = null): array
    {
        return $this->postJson('/api/inventory/recipes', ['name' => $name, 'type' => $type, 'components' => $components, 'yield_quantity' => '1', 'yield_unit' => $type === 'recipe' ? 'pz' : 'g', 'output_supply_id' => $output])->assertOk()->json('data');
    }

    private function operation(array $data, ?string $key = null)
    {
        return $this->postJson('/api/inventory/operations', $data + ['reason' => 'Prueba'], ['Idempotency-Key' => $key ?? (string)Str::uuid()]);
    }

    public function test_physical_count_sets_stock_and_replays_without_overwriting_new_activity(): void
    {
        $s = $this->supply('Coca');
        $key = (string) Str::uuid();
        $count = ['type' => 'COUNT', 'supply_id' => $s['id'], 'quantity' => '24', 'expected_quantity' => '0'];
        $id = $this->operation($count, $key)->assertOk()->assertJsonPath('data.lines.0.balance_after', '24.000000')->json('data.id');
        $this->operation(['type' => 'OUT', 'supply_id' => $s['id'], 'quantity' => '2'])->assertOk();
        $this->operation($count, $key)->assertOk()->assertJsonPath('data.id', $id);
        $this->operation($count)->assertConflict()->assertJsonPath('code', 'INVENTORY_COUNT_STALE');
        $this->operation(['type' => 'COUNT', 'supply_id' => $s['id'], 'quantity' => '0', 'expected_quantity' => '22'])->assertOk()->assertJsonPath('data.lines.0.quantity', '-22.000000');
        $this->operation(['type' => 'COUNT', 'supply_id' => $s['id'], 'quantity' => '-1', 'expected_quantity' => '0'])->assertUnprocessable();
        $this->assertDatabaseCount('inventory_operations', 3);
    }

    public function test_production_consumes_once_and_virtual_subrecipe_does_not_generate_stock(): void
    {
        $flour = $this->supply('Harina'); $dough = $this->supply('Masa', true);
        $sub = $this->recipe('Mezcla', 'subrecipe', [['type' => 'supply', 'id' => $flour['id'], 'quantity' => '2', 'unit' => 'g']]);
        $production = $this->recipe('Producir masa', 'production', [['type' => 'subrecipe', 'id' => $sub['id'], 'quantity' => '1', 'unit' => 'g']], $dough['id']);
        $this->operation(['type' => 'INITIAL', 'supply_id' => $flour['id'], 'quantity' => '10'])->assertOk();
        $key = (string)Str::uuid();
        $payload = ['type' => 'PRODUCTION', 'recipe_id' => $production['id'], 'version' => 1, 'batches' => '3'];
        $first = $this->operation($payload, $key)->assertOk()->json('data.id');
        $this->operation($payload, $key)->assertOk()->assertJsonPath('data.id', $first);
        $this->assertDatabaseCount('inventory_operations', 2);
        $this->assertDatabaseCount('inventory_balances', 2);
        $stocks = $this->getJson('/api/inventory/balances')->assertOk()->json('data');
        $this->assertSame('4.000000', collect($stocks)->firstWhere('name', 'Harina')['quantity']);
        $this->assertSame('3.000000', collect($stocks)->firstWhere('name', 'Masa')['quantity']);
        $this->operation($payload + ['unused' => 'ignored'], $key)->assertOk();
        $payload['batches'] = '4';
        $this->operation($payload, $key)->assertConflict()->assertJsonPath('code', 'INVENTORY_IDEMPOTENCY_CONFLICT');
    }

    public function test_negative_stock_and_initial_guard_and_invalid_units(): void
    {
        $s = $this->supply('Queso');
        $this->operation(['type' => 'WASTE', 'supply_id' => $s['id'], 'quantity' => '5'])->assertOk()->assertJsonPath('data.lines.0.negative', true);
        $this->operation(['type' => 'INITIAL', 'supply_id' => $s['id'], 'quantity' => '10'])->assertConflict();
        $this->operation(['type' => 'IN', 'supply_id' => $s['id'], 'quantity' => '-1'])->assertUnprocessable();
        $this->operation(['type' => 'IN', 'supply_id' => $s['id'], 'quantity' => '0'])->assertUnprocessable();
        $this->postJson('/api/inventory/recipes', ['name' => 'Incorrecta', 'type' => 'subrecipe', 'yield_quantity' => 1, 'yield_unit' => 'g', 'components' => [['type' => 'supply','id' => $s['id'],'quantity' => 1,'unit' => 'ml']]])->assertUnprocessable();
    }

    public function test_cycles_and_produced_self_consumption_rollback(): void
    {
        $s = $this->supply('Masa', true);
        $a = $this->recipe('A', 'subrecipe', [['type' => 'supply','id' => $s['id'],'quantity' => '1','unit' => 'g']]);
        $b = $this->recipe('B', 'subrecipe', [['type' => 'subrecipe','id' => $a['id'],'quantity' => '1','unit' => 'g']]);
        $this->putJson('/api/inventory/recipes/'.$a['id'], ['name' => 'A', 'type' => 'subrecipe','version' => 1,'yield_quantity' => 1,'yield_unit' => 'g', 'components' => [['type' => 'subrecipe','id' => $b['id'],'quantity' => 1,'unit' => 'g']]])->assertUnprocessable();
        $this->assertSame(1, DB::table('inventory_recipes')->where('public_id', $a['id'])->value('version'));
        $this->postJson('/api/inventory/recipes', ['name' => 'Auto', 'type' => 'production','yield_quantity' => 1,'yield_unit' => 'g','output_supply_id' => $s['id'],'components' => [['type' => 'subrecipe','id' => $a['id'],'quantity' => 1,'unit' => 'g']]])->assertUnprocessable();
        $this->assertDatabaseCount('inventory_recipes', 2);
    }

    public function test_sale_consumes_produced_supply_not_raw_material_and_replay_is_unique(): void
    {
        $flour = $this->supply('Harina'); $dough = $this->supply('Masa', true);
        $this->recipe('Producir', 'production', [['type' => 'supply','id' => $flour['id'],'quantity' => '2','unit' => 'g']], $dough['id']);
        $recipe = $this->recipe('Pizza', 'recipe', [['type' => 'supply','id' => $dough['id'],'quantity' => '250','unit' => 'g']]);
        app(\App\Tenancy\CurrentTenant::class)->set($this->tenant);
        $category = $this->tenant->categories()->create(['name' => 'Platillos', 'sort_order' => 0, 'is_active' => true]);
        $product = $this->tenant->products()->create(['name' => 'Pizza', 'category_id' => $category->id,'price' => 100, 'is_active' => true]);
        $this->putJson('/api/inventory/products/'.$product->public_id, ['type' => 'recipe', 'target_id' => $recipe['id'], 'quantity' => '1'])->assertOk();
        $response = $this->postCheckout(['order_type' => 'counter', 'payment_method' => 'cash', 'amount_received' => '200', 'items' => [['product_id' => $product->public_id, 'quantity' => 2]]])->assertCreated();
        app(\App\Tenancy\CurrentTenant::class)->set($this->tenant);
        $order = Order::where('public_id', $response->json('data.id'))->firstOrFail();
        app(TenantWriteTransaction::class)->run($this->tenant, fn () => app(InventoryLedgerService::class)->consumeOrder($this->tenant, $this->branch, $order));
        $this->assertDatabaseCount('inventory_operations', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('inventory_balances', ['quantity' => '-500.000000']);
    }

    public function test_pizza_pepperoni_flow_consumes_only_the_selling_branch_and_reconciles_a_count(): void
    {
        $flour = $this->supply('Harina para pizza');
        $cheese = $this->supply('Queso mozzarella');
        $sauce = $this->supply('Salsa de tomate');
        $pepperoni = $this->supply('Pepperoni');

        foreach ([[$flour, '4000'], [$cheese, '2500'], [$sauce, '1500'], [$pepperoni, '1200']] as [$supply, $amount]) {
            $this->operation(['type' => 'INITIAL', 'supply_id' => $supply['id'], 'quantity' => $amount])
                ->assertOk();
        }

        $recipe = $this->recipe('Pizza pepperoni', 'recipe', [
            ['type' => 'supply', 'id' => $flour['id'], 'quantity' => '250', 'unit' => 'g'],
            ['type' => 'supply', 'id' => $cheese['id'], 'quantity' => '150', 'unit' => 'g'],
            ['type' => 'supply', 'id' => $sauce['id'], 'quantity' => '80', 'unit' => 'g'],
            ['type' => 'supply', 'id' => $pepperoni['id'], 'quantity' => '60', 'unit' => 'g'],
        ]);
        app(\App\Tenancy\CurrentTenant::class)->set($this->tenant);
        $category = $this->tenant->categories()->create(['name' => 'Pizzas', 'is_active' => true]);
        $product = $this->tenant->products()->create(['name' => 'Pizza pepperoni', 'category_id' => $category->id, 'price' => 180, 'is_active' => true]);
        $this->putJson('/api/inventory/products/'.$product->public_id, ['type' => 'recipe', 'target_id' => $recipe['id'], 'quantity' => '1'])->assertOk();

        $sale = $this->postCheckout([
            'order_type' => 'counter',
            'payment_method' => 'cash',
            'amount_received' => '500',
            'items' => [['product_id' => $product->public_id, 'quantity' => 2]],
        ])->assertCreated();
        $order = Order::where('public_id', $sale->json('data.id'))->firstOrFail();
        $snapshot = $order->items()->sole()->inventory_snapshot;
        $this->assertSame('250.000000', collect($snapshot)->firstWhere('name', 'Harina para pizza')['quantity']);
        $this->assertSame('150.000000', collect($snapshot)->firstWhere('name', 'Queso mozzarella')['quantity']);

        $balances = collect($this->getJson('/api/inventory/balances')->assertOk()->json('data'))->keyBy('name');
        $this->assertSame('3500.000000', $balances['Harina para pizza']['quantity']);
        $this->assertSame('2200.000000', $balances['Queso mozzarella']['quantity']);
        $this->assertSame('1340.000000', $balances['Salsa de tomate']['quantity']);
        $this->assertSame('1080.000000', $balances['Pepperoni']['quantity']);

        $secondBranch = app(\App\Tenancy\CurrentTenant::class)->run($this->tenant, fn () => \App\Models\Branch::query()->create([
            'code' => 'PIZZA-02', 'name' => 'Sucursal pizza dos', 'timezone' => 'America/Mexico_City', 'is_active' => true, 'billing_enabled' => true,
        ]));
        $this->withHeader('X-Branch-ID', $secondBranch->public_id)
            ->getJson('/api/inventory/balances')
            ->assertOk()
            ->assertJsonPath('data.0.quantity', '0.000000');

        $this->withHeader('X-Branch-ID', $this->branch->public_id);
        $this->operation(['type' => 'COUNT', 'supply_id' => $flour['id'], 'quantity' => '3490', 'expected_quantity' => '3500'])
            ->assertOk()
            ->assertJsonPath('data.lines.0.quantity', '-10.000000')
            ->assertJsonPath('data.lines.0.balance_after', '3490.000000');
    }

    public function test_menu_products_list_filters_consumption_and_keeps_tenant_scope(): void
    {
        $supply = $this->supply('Harina');
        $recipe = $this->recipe('Pan', 'recipe', [['type' => 'supply', 'id' => $supply['id'], 'quantity' => '50', 'unit' => 'g']]);
        app(\App\Tenancy\CurrentTenant::class)->set($this->tenant);
        $category = $this->tenant->categories()->create(['name' => 'Panadería', 'is_active' => true]);
        $p = $this->tenant->products()->create(['name' => 'Pan del día', 'category_id' => $category->id, 'price' => 30, 'is_active' => true]);
        $deleted = $this->tenant->products()->create(['name' => 'Eliminado', 'category_id' => $category->id, 'price' => 10]);
        $deleted->delete();
        $other = app(CreateTenant::class)->handle($this->owner, Plan::where('code', 'free')->sole(), 'Otro', 'otro-inventario');
        app(\App\Tenancy\CurrentTenant::class)->set($other);
        $otherCategory = $other->categories()->create(['name' => 'Privada', 'is_active' => true]);
        $other->products()->create(['name' => 'Privado', 'category_id' => $otherCategory->id, 'price' => 20]);
        $this->getJson('/api/inventory/products?consumption=none')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $p->public_id);
        $this->putJson('/api/inventory/products/'.$p->public_id, ['type' => 'recipe', 'target_id' => $recipe['id'], 'quantity' => '1'])->assertOk();
        $this->getJson('/api/inventory/products?consumption=none')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/inventory/products?consumption=recipe&search=Pan')->assertOk()->assertJsonPath('data.0.consumption.name', 'Pan')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/inventory/products?search=Privado')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_product_consumption_requires_whole_pieces_but_allows_decimal_volume(): void
    {
        $pieces = $this->supply('Pan', false, 'pz');
        $liters = $this->supply('Leche', false, 'L');
        app(\App\Tenancy\CurrentTenant::class)->set($this->tenant);
        $category = $this->tenant->categories()->create(['name' => 'Bebidas', 'is_active' => true]);
        $product = $this->tenant->products()->create(['name' => 'Producto', 'category_id' => $category->id, 'price' => 30, 'is_active' => true]);

        $this->putJson('/api/inventory/products/'.$product->public_id, [
            'type' => 'supply', 'target_id' => $pieces['id'], 'quantity' => '1.5',
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->putJson('/api/inventory/products/'.$product->public_id, [
            'type' => 'supply', 'target_id' => $pieces['id'], 'quantity' => '2',
        ])->assertOk();

        $this->putJson('/api/inventory/products/'.$product->public_id, [
            'type' => 'supply', 'target_id' => $liters['id'], 'quantity' => '1.5',
        ])->assertOk();

        $this->assertDatabaseHas('inventory_product_links', [
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'quantity' => '1.500000',
        ]);
    }

    public function test_piece_units_are_whole_everywhere_stock_can_change(): void
    {
        $payload = [
            'id' => (string) Str::uuid(),
            'name' => 'Caja de pizza',
            'category' => 'Empaques',
            'unit' => 'pz',
            'minimum' => '1.5',
            'produced' => false,
            'presentations' => [],
        ];
        $this->postJson('/api/inventory/supplies', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('minimum');

        $pieces = $this->supply('Caja de pizza', false, 'pz');
        $this->operation(['type' => 'IN', 'supply_id' => $pieces['id'], 'quantity' => '1.5'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');
        $this->operation(['type' => 'COUNT', 'supply_id' => $pieces['id'], 'quantity' => '1.5', 'expected_quantity' => '0'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

        $this->postJson('/api/inventory/recipes', [
            'name' => 'Pizza con cajas fraccionadas',
            'type' => 'recipe',
            'yield_quantity' => '1',
            'yield_unit' => 'pz',
            'components' => [[
                'type' => 'supply',
                'id' => $pieces['id'],
                'quantity' => '0.5',
                'unit' => 'pz',
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('components.0.quantity');

        $liters = $this->supply('Salsa líquida', false, 'L');
        $this->operation(['type' => 'IN', 'supply_id' => $liters['id'], 'quantity' => '1.5'])
            ->assertOk()
            ->assertJsonPath('data.lines.0.balance_after', '1.500000');
    }

    public function test_intelligence_report_is_scoped_to_branch_and_downloads_as_pdf(): void
    {
        $supply = $this->postJson('/api/inventory/supplies', [
            'id' => (string) Str::uuid(),
            'name' => 'Pan para reporte',
            'category' => 'Panadería',
            'unit' => 'pz',
            'minimum' => '3',
            'ideal' => '6',
            'produced' => false,
            'presentations' => [],
        ])->assertCreated()->json('data');
        $this->operation(['type' => 'INITIAL', 'supply_id' => $supply['id'], 'quantity' => '2'])->assertOk();
        $dates = '?date_from='.now($this->branch->timezone)->toDateString().'&date_to='.now($this->branch->timezone)->toDateString();

        $this->getJson('/api/inventory/report'.$dates)
            ->assertOk()
            ->assertJsonPath('data.branch.id', $this->branch->public_id)
            ->assertJsonPath('data.summary.supplies', 1)
            ->assertJsonPath('data.summary.below_minimum', 1)
            ->assertJsonPath('data.shortages.0.name', 'Pan para reporte')
            ->assertJsonPath('data.shortages.0.suggested_restock', '4.000000');

        $pdf = $this->get('/api/inventory/report/pdf'.$dates)
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_excel_round_trip_and_repeat_import(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'inventory-test-');
        try {
            $writer = new Writer(); $writer->openToFile($path);
            $writer->addRow(Row::fromValues(InventoryExcelService::HEADERS));
            $writer->addRow(Row::fromValues(['Refresco','Bebidas','pz','','','no','Caja',24,'pz','Proveedor','REF',100,'']));
            $writer->addRow(Row::fromValues(['Refresco','Bebidas','pz','','','no','Paquete',6,'pz','Proveedor','REF6',30,'']));
            $writer->close();
            app(\App\Tenancy\CurrentTenant::class)->set($this->tenant);
            $service = app(InventoryExcelService::class);
            $preview = $service->preview($this->tenant, $this->owner, $path);
            $this->assertDatabaseCount('inventory_supplies', 0);
            $service->commit($this->tenant, $this->owner, $preview['id']);
            $service->commit($this->tenant, $this->owner, $preview['id']);
            $this->assertDatabaseCount('inventory_supplies', 1);
            $this->assertDatabaseCount('inventory_presentations', 2);
            $export = $service->export($this->tenant, false);
            try { $this->assertGreaterThan(100, filesize($export)); } finally { unlink($export); }
        } finally { @unlink($path); }
    }

    public function test_cross_tenant_recipe_and_branch_operations_are_denied(): void
    {
        $s = $this->supply('Harina');
        $other = app(CreateTenant::class)->handle($this->owner, Plan::where('code', 'free')->sole(), 'Otro', 'otro');
        $this->withHeader('X-Tenant-ID', $other->public_id);
        $this->operation(['type' => 'IN','supply_id' => $s['id'],'quantity' => '1'])->assertNotFound();
        $this->withHeader('X-Branch-ID', \App\Models\Branch::withoutGlobalScopes()->where('tenant_id', $other->id)->first()->public_id);
        $this->postJson('/api/inventory/recipes', ['name' => 'Ajena','type' => 'recipe','yield_quantity' => 1,'yield_unit' => 'pz','components' => [['type' => 'supply','id' => $s['id'],'quantity' => 1,'unit' => 'g']]])->assertUnprocessable();
        $this->assertDatabaseCount('inventory_operations', 0);
    }
}
