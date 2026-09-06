<?php

namespace Tests\Feature\Pos;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Enums\PlanCode;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_creates_tax_snapshots_from_current_product_price(): void
    {
        [$owner, $tenant] = $this->provisionOwner();
        $product = $this->createProduct($tenant, 'Pizza', '150.00');

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->postJson('/api/pos/orders', [
                'order_type' => 'counter',
                'payment_method' => 'cash',
                'items' => [
                    [
                        'product_id' => $product->public_id,
                        'quantity' => 2,
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.total', '300.00')
            ->assertJsonPath('data.tax_total', '41.38')
            ->assertJsonPath('data.items.0.line_total', '300.00')
            ->assertJsonPath('data.items.0.tax_base', '258.62')
            ->assertJsonPath('data.items.0.tax_amount', '41.38');

        $item = Order::query()->sole()->items()->sole();

        $this->assertSame(
            $item->line_total,
            number_format(
                (float) $item->tax_base + (float) $item->tax_amount,
                2,
                '.',
                '',
            ),
        );
    }

    public function test_changing_default_tax_does_not_change_historical_snapshot(): void
    {
        [$owner, $tenant] = $this->provisionOwner();
        $product = $this->createProduct($tenant, 'Pizza', '150.00');

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->postJson('/api/pos/orders', [
                'order_type' => 'counter',
                'payment_method' => 'card',
                'items' => [
                    [
                        'product_id' => $product->public_id,
                        'quantity' => 1,
                    ],
                ],
            ])
            ->assertCreated();

        app(CurrentTenant::class)->run($tenant, function () use ($tenant): void {
            $zero = $tenant->taxes()->where('name', 'IVA 0%')->sole();
            $tenant->taxSettings()->firstOrFail()->update([
                'default_tax_id' => $zero->id,
            ]);
        });

        $item = Order::query()->sole()->items()->sole();

        $this->assertSame('IVA 16%', $item->tax_name_snapshot);
        $this->assertSame('20.69', $item->tax_amount);
        $this->assertSame('150.00', $item->line_total);
    }

    public function test_monthly_sales_limit_blocks_sale_that_exceeds_plan(): void
    {
        [$owner, $tenant] = $this->provisionOwner();
        $product = $this->createProduct($tenant, 'Banquete', '150.00');

        app(CurrentTenant::class)->run($tenant, function () use ($tenant, $owner): void {
            $branch = Branch::query()->sole();

            $tenant->orders()->create([
                'branch_id' => $branch->id,
                'folio' => 'POS-000001',
                'order_type' => 'counter',
                'status' => 'paid',
                'subtotal' => 24900,
                'tax_base_total' => 21465.52,
                'tax_total' => 3434.48,
                'discount_total' => 0,
                'service_charge_total' => 0,
                'tip_total' => 0,
                'total' => 24900,
                'payment_method' => 'cash',
                'paid_at' => now(),
                'created_by' => $owner->id,
            ]);
        });

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->postJson('/api/pos/orders', [
                'order_type' => 'counter',
                'payment_method' => 'cash',
                'items' => [
                    [
                        'product_id' => $product->public_id,
                        'quantity' => 1,
                    ],
                ],
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'MONTHLY_SALES_LIMIT_EXCEEDED');
    }

    public function test_checkout_fails_when_free_branch_cannot_be_resolved(): void
    {
        [$owner, $tenant] = $this->provisionOwner();
        $product = $this->createProduct($tenant, 'Pizza', '150.00');

        app(CurrentTenant::class)->run($tenant, function (): void {
            Branch::query()->create([
                'code' => 'SEG',
                'name' => 'Segunda',
                'timezone' => 'America/Mexico_City',
                'is_active' => true,
            ]);
        });

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->postJson('/api/pos/orders', [
                'order_type' => 'counter',
                'payment_method' => 'cash',
                'items' => [
                    [
                        'product_id' => $product->public_id,
                        'quantity' => 1,
                    ],
                ],
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'POS_BRANCH_NOT_RESOLVED');
    }

    private function createProduct(
        Tenant $tenant,
        string $name,
        string $price,
    ): Product {
        return app(CurrentTenant::class)->run(
            $tenant,
            function () use ($tenant, $name, $price): Product {
                $category = $tenant->categories()->create([
                    'name' => 'Comida',
                    'sort_order' => 0,
                    'is_active' => true,
                ]);

                return $tenant->products()->create([
                    'category_id' => $category->id,
                    'name' => $name,
                    'description' => null,
                    'price' => $price,
                    'image_path' => null,
                    'sort_order' => 0,
                    'is_active' => true,
                ]);
            },
        );
    }

    /**
     * @return array{User, Tenant}
     */
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
