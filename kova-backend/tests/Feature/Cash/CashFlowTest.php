<?php

namespace Tests\Feature\Cash;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Carbon\Carbon;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashFlowTest extends TestCase
{
    use RefreshDatabase;

    private $tenant;

    private $owner;

    private $branch;

    private string $register;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlanSeeder::class, RoleSeeder::class]);
        $this->owner = User::factory()->create(['email_verified_at' => now()]);
        $this->tenant = app(CreateTenant::class)->handle($this->owner, Plan::where('code', 'free')->sole(), 'Caja', 'caja');
        $this->branch = app(CurrentTenant::class)->run($this->tenant, fn () => $this->tenant->branches()->firstOrFail());
        $this->capacity(5);
        $this->actingAs($this->owner)->withHeaders(['X-Tenant-ID' => $this->tenant->public_id, 'X-Branch-ID' => $this->branch->public_id]);
        $this->register = $this->postJson('/api/cash/registers', ['name' => 'Principal'])->assertCreated()->json('data.id');
    }

    private function capacity(int $limit): void
    {
        DB::table('plan_entitlements')->where('plan_version_id', DB::table('plan_subscriptions')->where('current_tenant_id', $this->tenant->id)->value('plan_version_id'))
            ->where('feature_id', Feature::where('code', 'max_users')->value('id'))->update(['value' => $limit, 'is_unlimited' => false]);
    }

    private function operation(string $path, array $data, ?string $key = null)
    {
        return $this->postJson('/api/cash/'.$path, $data, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function open(): string
    {
        return $this->operation('shifts', ['register_id' => $this->register, 'opening_cash' => '1000.00'])->assertCreated()->json('data.id');
    }

    private function submitCount(string $shift, string $amount): string
    {
        return $this->operation("shifts/$shift/counts", ['counted_cash' => $amount])->assertCreated()->json('data.id');
    }

    public function test_complete_cycle_uses_net_cash_and_replays_sale_after_closing(): void
    {
        $shift = $this->open();
        $product = app(CurrentTenant::class)->run($this->tenant, function () {
            $category = $this->tenant->categories()->create(['name' => 'Comida', 'is_active' => true]);

            return $this->tenant->products()->create(['name' => 'Café', 'price' => '180.00', 'category_id' => $category->id, 'is_active' => true]);
        });
        $items = [['product_id' => $product->public_id, 'quantity' => 1]];
        $quote = $this->postJson('/api/pos/checkout/quote', ['items' => $items])->assertOk()->json('data.quote_token');
        $payload = ['order_type' => 'counter', 'payment_method' => 'cash', 'amount_received' => '200.00', 'items' => $items, 'quote_token' => $quote];
        $key = (string) Str::uuid();
        $sale = $this->postJson('/api/pos/orders', $payload, ['Idempotency-Key' => $key])->assertCreated()->assertJsonPath('data.change_amount', '20.00')->json('data');
        $this->operation("shifts/$shift/movements", ['type' => 'IN', 'amount' => '100.00', 'reason' => 'Cambio'])->assertCreated();
        $this->operation("shifts/$shift/movements", ['type' => 'OUT', 'amount' => '350.00', 'reason' => 'Insumos'])->assertCreated();
        $count = $this->operation("shifts/$shift/counts", ['counted_cash' => '910.00'])->assertCreated()->assertJsonPath('data.expected_cash', '930.00')->assertJsonPath('data.difference', '-20.00')->json('data.id');
        $this->operation("shifts/$shift/close", ['count_id' => $count])->assertConflict()->assertJsonPath('code', 'CASH_DIFFERENCE_REASON_REQUIRED');
        $closeKey = (string) Str::uuid();
        $close = ['count_id' => $count, 'difference_reason' => 'Error de cambio'];
        $first = $this->operation("shifts/$shift/close", $close, $closeKey)->assertCreated()->assertJsonPath('data.status', 'CLOSED')->json('data');
        $this->operation("shifts/$shift/close", $close, $closeKey)->assertOk()->assertJsonPath('data', $first);
        $this->postJson('/api/pos/orders', $payload, ['Idempotency-Key' => $key])->assertOk()->assertJsonPath('data', $sale);
        $this->assertDatabaseCount('orders', 1);
        $this->operation("shifts/$shift/movements", ['type' => 'IN', 'amount' => '1', 'reason' => 'Tarde'])->assertConflict()->assertJsonPath('code', 'CASH_SHIFT_CLOSED');
    }

    public function test_stale_counts_and_idempotency_cannot_change_history(): void
    {
        $shift = $this->open();
        $count = $this->submitCount($shift, '1000');
        $key = (string) Str::uuid();
        $data = ['type' => 'IN', 'amount' => '10', 'reason' => 'Cambio'];
        $this->operation("shifts/$shift/movements", $data, $key)->assertCreated();
        $this->operation("shifts/$shift/movements", $data, $key)->assertOk();
        $this->operation("shifts/$shift/movements", [...$data, 'amount' => '11'], $key)->assertConflict()->assertJsonPath('code', 'CASH_IDEMPOTENCY_CONFLICT');
        $this->operation("shifts/$shift/close", ['count_id' => $count])->assertConflict()->assertJsonPath('code', 'CASH_COUNT_STALE');
        $this->assertDatabaseCount('cash_movements', 1);
        $this->operation("shifts/$shift/movements", ['type' => 'OUT', 'amount' => '1011', 'reason' => 'Demasiado'])->assertConflict()->assertJsonPath('code', 'CASH_INSUFFICIENT_FUNDS');
    }

    public function test_unique_open_slots_and_invalid_inputs(): void
    {
        $shift = $this->open();
        $this->operation('shifts', ['register_id' => $this->register, 'opening_cash' => '0'])->assertConflict()->assertJsonPath('code', 'CASH_REGISTER_BUSY');
        $register = $this->postJson('/api/cash/registers', ['name' => 'Otra'])->assertCreated()->json('data.id');
        $this->operation('shifts', ['register_id' => $register, 'opening_cash' => '0'])->assertConflict()->assertJsonPath('code', 'CASH_USER_ALREADY_HAS_SHIFT');
        $this->patchJson('/api/cash/registers/'.$this->register, ['is_active' => false])->assertConflict();
        foreach (['0', '-1', '0.001'] as $amount) {
            $this->operation("shifts/$shift/movements", ['type' => 'IN', 'amount' => $amount, 'reason' => 'Prueba'])->assertUnprocessable();
        }
        $this->operation("shifts/$shift/counts", ['counted_cash' => '1000', 'denominations' => ['100000' => 1]])->assertUnprocessable();
        $this->operation("shifts/$shift/counts", ['denominations' => ['7' => 1]])->assertUnprocessable();
        $this->operation("shifts/$shift/counts", ['denominations' => ['100000' => 1, '20' => 3]])->assertCreated()->assertJsonPath('data.counted_cash', '1000.60');
        $this->operation("shifts/$shift/counts", ['counted_cash' => '1000', 'tenant_id' => 123])->assertUnprocessable();
    }

    public function test_downgrade_allows_count_and_close_but_not_new_operations(): void
    {
        $shift = $this->open();
        $this->capacity(1);
        $this->operation("shifts/$shift/movements", ['type' => 'IN', 'amount' => '1', 'reason' => 'No'])->assertConflict()->assertJsonPath('code', 'CASH_FEATURE_UNAVAILABLE');
        $this->getJson('/api/cash/context')->assertOk()->assertJsonPath('data.can_checkout', false);
        $count = $this->submitCount($shift, '1000');
        $this->operation("shifts/$shift/close", ['count_id' => $count])->assertCreated();
        $this->getJson('/api/cash/context')->assertOk()->assertJsonPath('data.can_checkout', true);
    }

    public function test_cashier_blind_count_permissions_and_foreign_shift(): void
    {
        $shift = $this->open();
        $staff = User::factory()->create(['email_verified_at' => now()]);
        $membership = DB::table('memberships')->insertGetId(['public_id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'user_id' => $staff->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branch_memberships')->insert(['membership_id' => $membership, 'branch_id' => $this->branch->id, 'created_at' => now(), 'updated_at' => now()]);
        setPermissionsTeamId($this->tenant->id);
        $staff->assignRole('cashier');
        setPermissionsTeamId(null);
        $register = $this->postJson('/api/cash/registers', ['name' => 'Cajero'])->assertCreated()->json('data.id');
        $this->actingAs($staff);
        $this->getJson('/api/cash/shifts/'.$shift)->assertForbidden();
        $own = $this->operation('shifts', ['register_id' => $register, 'opening_cash' => '0'])->assertCreated()->assertJsonMissingPath('data.expected_cash')->assertJsonMissingPath('data.difference')->json('data.id');
        $this->getJson('/api/cash/shifts/'.$own)->assertOk()->assertJsonMissingPath('data.expected_cash');
        $this->getJson('/api/cash/shifts')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.expected_cash');
        $this->operation("shifts/$own/counts", ['counted_cash' => '0'])->assertCreated()->assertJsonPath('data.expected_cash', '0.00');
        $this->postJson('/api/cash/registers', ['name' => 'No'])->assertForbidden();
    }

    public function test_suspension_and_blocked_branch_allow_supervised_close_only(): void
    {
        $shift = $this->open();
        $supervisor = User::factory()->create(['email_verified_at' => now()]);
        DB::table('memberships')->insert(['public_id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'user_id' => $supervisor->id, 'status' => 'active']);
        setPermissionsTeamId($this->tenant->id);
        $supervisor->assignRole('owner');
        setPermissionsTeamId(null);
        DB::table('memberships')->where('tenant_id', $this->tenant->id)->where('user_id', $this->owner->id)->update(['status' => 'suspended']);
        $this->getJson('/api/cash/shifts/'.$shift)->assertNotFound();
        $this->actingAs($supervisor);
        $this->operation("shifts/$shift/counts", ['counted_cash' => '1000'])->assertUnprocessable();
        DB::table('branches')->where('id', $this->branch->id)->update(['billing_enabled' => false]);
        DB::table('plan_subscriptions')->where('current_tenant_id', $this->tenant->id)->update(['status' => 'suspended']);
        $count = $this->operation("shifts/$shift/counts", ['counted_cash' => '1000', 'intervention_reason' => 'Cierre por suspensión'])->assertCreated()->json('data.id');
        $this->operation("shifts/$shift/close", ['count_id' => $count, 'intervention_reason' => 'Cierre por suspensión'])->assertCreated()->assertJsonPath('data.closed_by', $supervisor->name);
        $this->getJson('/api/navigation')->assertOk()->assertJsonFragment(['key' => 'cash']);
    }

    public function test_card_and_transfer_do_not_change_physical_cash(): void
    {
        $shift = $this->open();
        $product = app(CurrentTenant::class)->run($this->tenant, function () {
            $category = $this->tenant->categories()->create(['name' => 'Comida', 'is_active' => true]);

            return $this->tenant->products()->create(['name' => 'Producto', 'price' => '10.13', 'category_id' => $category->id, 'is_active' => true]);
        });
        $items = [['product_id' => $product->public_id, 'quantity' => 1]];
        foreach (['card', 'transfer'] as $method) {
            $token = $this->postJson('/api/pos/checkout/quote', ['items' => $items])->assertOk()->json('data.quote_token');
            $this->postJson('/api/pos/orders', ['items' => $items, 'order_type' => 'counter', 'payment_method' => $method, 'quote_token' => $token], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        }
        $this->operation("shifts/$shift/counts", ['counted_cash' => '1000'])->assertCreated()
            ->assertJsonPath('data.expected_cash', '1000.00')->assertJsonPath('data.card_sales_total', '10.13')->assertJsonPath('data.transfer_sales_total', '10.13')->assertJsonPath('data.cash_refunds_total', '0.00');
    }

    public function test_cross_tenant_registers_and_internal_ids_are_rejected(): void
    {
        $other = User::factory()->create(['email_verified_at' => now()]);
        $tenant = app(CreateTenant::class)->handle($other, Plan::where('code', 'free')->sole(), 'Otra', 'otra');
        $otherBranch = app(CurrentTenant::class)->run($tenant, fn () => $tenant->branches()->firstOrFail());
        $foreign = (string) Str::uuid();
        DB::table('cash_registers')->insert(['tenant_id' => $tenant->id, 'branch_id' => $otherBranch->id, 'public_id' => $foreign, 'name' => 'Ajena']);
        $this->operation('shifts', ['register_id' => $foreign, 'opening_cash' => '0'])->assertNotFound();
        $this->operation('shifts', ['register_id' => '1', 'opening_cash' => '0'])->assertUnprocessable();
        $this->operation('shifts', ['register_id' => $this->register, 'opening_cash' => '0', 'responsible_user_id' => $other->id])->assertUnprocessable();
        $shift = $this->open();
        $this->actingAs($other)->withHeader('X-Tenant-ID', $tenant->public_id)->getJson('/api/cash/shifts/'.$shift)->assertNotFound();
        $this->assertDatabaseCount('cash_shifts', 1);
    }

    public function test_operational_date_is_fixed_at_opening_in_branch_timezone(): void
    {
        $this->travelTo(Carbon::parse('2026-09-27 04:00:00', 'UTC'));
        DB::table('branches')->where('id', $this->branch->id)->update(['timezone' => 'America/Mexico_City']);
        $shift = $this->open();
        $this->getJson('/api/cash/shifts/'.$shift)->assertJsonPath('data.business_date', '2026-09-26');
        $this->travel(5)->hours();
        $count = $this->submitCount($shift, '1000');
        $this->operation("shifts/$shift/close", ['count_id' => $count])->assertCreated()->assertJsonPath('data.business_date', '2026-09-26')->assertJsonPath('data.opened_timezone', 'America/Mexico_City');
    }

    public function test_transaction_failure_rolls_back_cash_movement_and_activity(): void
    {
        $shift = $this->open();
        DB::unprepared("CREATE TRIGGER fail_cash_operation BEFORE INSERT ON cash_operations BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
        $key = (string) Str::uuid();
        $data = ['type' => 'IN', 'amount' => '20.00', 'reason' => 'Cambio'];
        $this->operation("shifts/$shift/movements", $data, $key)->assertServerError();
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame(0, (int) DB::table('cash_shifts')->value('activity_version'));
        DB::unprepared('DROP TRIGGER fail_cash_operation');
        $this->operation("shifts/$shift/movements", $data, $key)->assertCreated();
    }

    public function test_closed_history_and_movements_reject_direct_changes(): void
    {
        $shift = $this->open();
        $this->operation("shifts/$shift/movements", ['type' => 'IN', 'amount' => '1', 'reason' => 'Cambio'])->assertCreated();
        $count = $this->submitCount($shift, '1001');
        $this->operation("shifts/$shift/close", ['count_id' => $count])->assertCreated();
        foreach ([fn () => DB::table('cash_shifts')->update(['counted_cash' => '0']), fn () => DB::table('cash_movements')->update(['amount' => '50']), fn () => DB::table('cash_movements')->delete()] as $mutation) {
            try {
                $mutation();
                $this->fail('History was modified');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_feature_and_shift_changes_invalidate_prepared_checkout(): void
    {
        $shift = $this->open();
        $product = app(CurrentTenant::class)->run($this->tenant, function () {
            $c = $this->tenant->categories()->create(['name' => 'Comida', 'is_active' => true]);

            return $this->tenant->products()->create(['name' => 'Producto', 'price' => '10', 'category_id' => $c->id, 'is_active' => true]);
        });
        $items = [['product_id' => $product->public_id, 'quantity' => 1]];
        $quote = $this->postJson('/api/pos/checkout/quote', ['items' => $items])->assertOk()->json('data.quote_token');
        $count = $this->submitCount($shift, '1000');
        $this->operation("shifts/$shift/close", ['count_id' => $count])->assertCreated();
        $payload = ['quote_token' => $quote, 'items' => $items, 'payment_method' => 'card', 'order_type' => 'counter'];
        $this->postJson('/api/pos/orders',$payload,['Idempotency-Key' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code','CASH_SHIFT_REQUIRED');
        $this->open();
        $this->postJson('/api/pos/orders',$payload,['Idempotency-Key' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code','CASH_QUOTE_CHANGED');
        $this->assertDatabaseCount('orders',0);
    }
}
