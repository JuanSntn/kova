<?php

namespace Tests\Feature\Pos;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Models\Plan;
use App\Models\User;
use App\Models\Order;
use App\Models\Ticket;
use App\Tenancy\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CheckoutRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->seed([PlanSeeder::class, RoleSeeder::class]);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $tenant = app(CreateTenant::class)->handle($user, Plan::where('code', 'free')->sole(), 'Tienda', 'tienda');
        $product = app(CurrentTenant::class)->run($tenant, function () use ($tenant) {
            $category = $tenant->categories()->create(['name' => 'Comida', 'is_active' => true]);
            return $tenant->products()->create(['name' => 'Café', 'price' => '0.17', 'category_id' => $category->id, 'is_active' => true]);
        });
        $this->actingAs($user)->withHeader('X-Tenant-ID', $tenant->public_id);
        $items = [['product_id' => $product->public_id, 'quantity' => 3]];
        $quote = $this->postJson('/api/pos/checkout/quote', ['items' => $items])->assertOk()->json('data');
        $payload = ['order_type' => 'counter', 'payment_method' => 'cash', 'amount_received' => '1.00', 'items' => $items, 'quote_token' => $quote['quote_token']];
        return [$user, $tenant, $product, $payload, (string) Str::uuid()];
    }

    public function test_lost_response_recovers_the_original_snapshots_and_one_fiscal_ticket(): void
    {
        [$user, $tenant, $product, $payload, $key] = $this->fixture();
        config(['fiscal.enabled' => true, 'fiscal.encryption_keys.1' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->enableTenantFeature($tenant, 'allows_invoicing');
        $first = $this->postJson('/api/pos/orders', $payload, ['Idempotency-Key' => $key])->assertCreated()
            ->assertJsonPath('data.total', '0.51')->assertJsonPath('data.change_amount', '0.49')->json('data');
        app(CurrentTenant::class)->run($tenant, function () use ($product, $tenant) {
            $product->update(['price' => '500.00', 'billing_enabled' => false]);
            $tenant->branches()->update(['billing_enabled' => false]);
        });
        $this->travel(10)->minutes();
        $this->getJson('/api/pos/checkouts/'.$key)->assertOk()->assertJsonPath('data', $first);
        $this->postJson('/api/pos/orders', $payload, ['Idempotency-Key' => $key])->assertOk()->assertJsonPath('data', $first);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('pos_checkout_operations', 1);
    }

    public function test_same_key_with_other_content_or_branch_is_rejected(): void
    {
        [, , , $payload, $key] = $this->fixture();
        $this->postJson('/api/pos/orders', $payload, ['Idempotency-Key' => $key])->assertCreated();
        $this->postJson('/api/pos/orders', [...$payload, 'amount_received' => '2.00'], ['Idempotency-Key' => $key])
            ->assertConflict()->assertJsonPath('code', 'POS_IDEMPOTENCY_CONFLICT');
        $this->postJson('/api/pos/orders', $payload, ['Idempotency-Key' => $key, 'X-Branch-ID' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'POS_IDEMPOTENCY_CONFLICT');
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_price_changes_and_expiry_require_a_new_confirmation(): void
    {
        [, $tenant, $product, $payload, $key] = $this->fixture();
        app(CurrentTenant::class)->run($tenant, fn () => $product->update(['price' => '0.18']));
        $this->postJson('/api/pos/orders', $payload, ['Idempotency-Key' => $key])
            ->assertConflict()->assertJsonPath('code', 'POS_QUOTE_CHANGED');
        $this->travel(6)->minutes();
        $this->postJson('/api/pos/orders', $payload, ['Idempotency-Key' => $key])
            ->assertConflict()->assertJsonPath('code', 'POS_QUOTE_EXPIRED');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('pos_checkout_operations', 0);
    }

    public function test_tax_change_with_same_gross_total_requires_confirmation(): void
    {
        [, $tenant, , $payload, $key] = $this->fixture();
        app(CurrentTenant::class)->run($tenant, function () use ($tenant) {
            $tenant->taxSettings()->firstOrFail()->update(['default_tax_id' => $tenant->taxes()->where('name', 'IVA 0%')->sole()->id]);
        });
        $this->postJson('/api/pos/orders', $payload, ['Idempotency-Key' => $key])
            ->assertConflict()->assertJsonPath('code', 'POS_QUOTE_CHANGED');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_transaction_failure_rolls_back_order_items_ticket_and_operation(): void
    {
        [, , , $payload, $key] = $this->fixture();
        DB::unprepared("CREATE TRIGGER fail_checkout BEFORE INSERT ON pos_checkout_operations BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
        $this->postJson('/api/pos/orders', $payload, ['Idempotency-Key' => $key])->assertServerError();
        foreach (['orders', 'order_items', 'tickets', 'pos_checkout_operations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        DB::unprepared('DROP TRIGGER fail_checkout');
        $this->postJson('/api/pos/orders', $payload, ['Idempotency-Key' => $key])->assertCreated();
    }

    public function test_missing_result_can_be_retried_only_with_original_key(): void
    {
        [, , , $payload, $key] = $this->fixture();
        $this->getJson('/api/pos/checkouts/'.$key)->assertNotFound()->assertJsonPath('code', 'POS_CHECKOUT_NOT_FOUND');
        $this->postJson('/api/pos/orders', $payload, ['Idempotency-Key' => $key])->assertCreated();
        $this->getJson('/api/pos/checkouts/'.$key)->assertOk();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_header_confirmation_and_amount_validation_are_required(): void
    {
        [, , , $payload, $key] = $this->fixture();
        $this->postJson('/api/pos/orders', $payload)->assertUnprocessable();
        $this->postJson('/api/pos/orders', [...$payload, 'quote_token' => 'tampered'], ['Idempotency-Key' => $key])
            ->assertConflict()->assertJsonPath('code', 'POS_QUOTE_INVALID');
        $this->postJson('/api/pos/orders', [...$payload, 'amount_received' => '0.50'], ['Idempotency-Key' => $key])
            ->assertConflict()->assertJsonPath('code', 'INSUFFICIENT_CASH_RECEIVED');
        $this->postJson('/api/pos/orders', [...$payload, 'amount_received' => '1.001'], ['Idempotency-Key' => $key])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_other_user_and_tenant_cannot_recover_the_order(): void
    {
        [, $tenant, , $payload, $key] = $this->fixture();
        $this->postJson('/api/pos/orders', $payload, ['Idempotency-Key' => $key])->assertCreated();
        $other = User::factory()->create(['email_verified_at' => now()]);
        $otherTenant = app(CreateTenant::class)->handle($other, Plan::where('code', 'free')->sole(), 'Otra', 'otra');
        $this->actingAs($other)->withHeader('X-Tenant-ID', $otherTenant->public_id)
            ->getJson('/api/pos/checkouts/'.$key)->assertNotFound();
        $this->withHeader('X-Tenant-ID', $tenant->public_id)->getJson('/api/pos/checkouts/'.$key)->assertNotFound();
    }
}
