<?php

namespace Tests\Feature\Taxes;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Enums\PlanCode;
use App\Models\Category;
use App\Models\Plan;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Taxes\ProductTaxService;
use App\Services\Taxes\TenantTaxProvisioner;
use App\Tenancy\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTaxTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_gets_default_tax_catalog_idempotently(): void
    {
        [, $tenant] = $this->provisionOwner();

        app(CurrentTenant::class)->run($tenant, function () use ($tenant): void {
            $this->assertSame(3, Tax::query()->count());
            $this->assertDatabaseHas('tenant_tax_settings', [
                'tenant_id' => $tenant->id,
                'prices_include_tax' => true,
            ]);

            app(TenantTaxProvisioner::class)->provisionDefaults($tenant);

            $this->assertSame(3, Tax::query()->count());
        });
    }

    public function test_product_default_specific_null_and_absent_tax_modes(): void
    {
        [$owner, $tenant] = $this->provisionOwner();

        [$category, $exemptTax] = app(CurrentTenant::class)->run(
            $tenant,
            fn (): array => [
                $tenant->categories()->create([
                    'name' => 'Comida',
                    'sort_order' => 0,
                    'is_active' => true,
                ]),
                Tax::query()
                    ->where('factor_type', 'exempt')
                    ->sole(),
            ],
        );

        $create = $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->postJson('/api/pos/products', [
                'category_id' => $category->public_id,
                'name' => 'Pizza',
                'price' => '150.00',
                'tax_id' => $exemptTax->public_id,
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.tax.mode', 'specific')
            ->assertJsonPath('data.tax.id', $exemptTax->public_id);

        $productId = $create->json('data.id');

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->putJson("/api/pos/products/{$productId}", [
                'name' => 'Pizza grande',
            ])
            ->assertOk()
            ->assertJsonPath('data.tax.mode', 'specific')
            ->assertJsonPath('data.tax.id', $exemptTax->public_id);

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->putJson("/api/pos/products/{$productId}", [
                'tax_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.tax.mode', 'default')
            ->assertJsonPath('data.tax.id', null)
            ->assertJsonPath('data.tax.effective.name', 'IVA 16%');
    }

    public function test_foreign_tenant_tax_cannot_be_assigned_to_product(): void
    {
        [$owner, $tenant] = $this->provisionOwner();
        [, $otherTenant] = $this->provisionOwner('other@example.test', 'Otro');

        $category = app(CurrentTenant::class)->run(
            $tenant,
            fn (): Category => $tenant->categories()->create([
                'name' => 'Comida',
                'sort_order' => 0,
                'is_active' => true,
            ]),
        );

        $foreignTax = app(CurrentTenant::class)->run(
            $otherTenant,
            fn (): Tax => $otherTenant
                ->taxes()
                ->where('name', 'IVA 0%')
                ->sole(),
        );

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->postJson('/api/pos/products', [
                'category_id' => $category->public_id,
                'name' => 'Pizza',
                'price' => '150.00',
                'tax_id' => $foreignTax->public_id,
                'is_active' => true,
            ])
            ->assertNotFound();
    }

    public function test_included_tax_keeps_gross_total(): void
    {
        [, $tenant] = $this->provisionOwner();

        app(CurrentTenant::class)->run($tenant, function (): void {
            $tax = Tax::query()->where('name', 'IVA 16%')->sole();
            $result = app(ProductTaxService::class)->calculateIncludedTax(
                150,
                $tax,
            );

            $this->assertSame('129.31', $result['tax_base']);
            $this->assertSame('20.69', $result['tax_amount']);
            $this->assertSame('150.00', $result['line_total']);
            $this->assertSame(
                $result['line_total'],
                (string) \Brick\Math\BigDecimal::of($result['tax_base'])->plus($result['tax_amount']),
            );

            $zero = Tax::query()->where('name', 'IVA 0%')->sole();
            $exempt = Tax::query()->where('factor_type', 'exempt')->sole();

            $this->assertSame('0.00', app(ProductTaxService::class)->calculateIncludedTax(150, $zero)['tax_amount']);
            $this->assertSame('0.00', app(ProductTaxService::class)->calculateIncludedTax(150, $exempt)['tax_amount']);
        });
    }

    public function test_default_tax_cannot_be_deactivated(): void
    {
        [$owner, $tenant] = $this->provisionOwner();

        $defaultTax = app(CurrentTenant::class)->run(
            $tenant,
            fn (): Tax => Tax::query()->where('name', 'IVA 16%')->sole(),
        );

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->putJson("/api/settings/taxes/{$defaultTax->public_id}", [
                'is_active' => false,
            ])
            ->assertUnprocessable();
    }

    public function test_owner_cannot_create_an_arbitrary_tax_treatment(): void
    {
        [$owner, $tenant] = $this->provisionOwner();

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->postJson('/api/settings/taxes', [
                'name' => 'IVA 8%',
                'code' => 'iva',
                'factor_type' => 'rate',
                'rate' => 8,
                'is_active' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.tax.0',
                'Kova administra los tratamientos fiscales disponibles. No puedes crear tasas manuales.',
            );

        $this->assertDatabaseMissing('taxes', [
            'tenant_id' => $tenant->id,
            'factor_type' => 'rate',
            'rate' => '8.0000',
        ]);
    }

    public function test_tax_identity_and_rate_cannot_be_modified(): void
    {
        [$owner, $tenant] = $this->provisionOwner();

        $zeroTax = app(CurrentTenant::class)->run(
            $tenant,
            fn (): Tax => Tax::query()->where('name', 'IVA 0%')->sole(),
        );

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->putJson("/api/settings/taxes/{$zeroTax->public_id}", [
                'name' => 'IVA 8%',
                'rate' => 8,
                'is_active' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'rate']);

        $this->assertDatabaseHas('taxes', [
            'id' => $zeroTax->id,
            'name' => 'IVA 0%',
            'rate' => '0.0000',
        ]);
    }

    public function test_tax_assigned_to_products_cannot_be_deactivated(): void
    {
        [$owner, $tenant] = $this->provisionOwner();

        [, $zeroTax] = app(CurrentTenant::class)->run(
            $tenant,
            function () use ($tenant): array {
                $category = $tenant->categories()->create([
                    'name' => 'Comida',
                    'sort_order' => 0,
                    'is_active' => true,
                ]);

                $zeroTax = Tax::query()->where('name', 'IVA 0%')->sole();

                $tenant->products()->create([
                    'category_id' => $category->id,
                    'tax_id' => $zeroTax->id,
                    'name' => 'Agua',
                    'price' => '20.00',
                    'sort_order' => 0,
                    'is_active' => true,
                    'billing_enabled' => true,
                ]);

                return [$category, $zeroTax];
            },
        );

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->putJson("/api/settings/taxes/{$zeroTax->public_id}", [
                'is_active' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.is_active.0',
                'No puedes desactivar un impuesto que está asignado a productos.',
            );

        $this->assertTrue($zeroTax->fresh()->is_active);
    }

    public function test_legacy_custom_taxes_are_not_listed_or_assignable(): void
    {
        [$owner, $tenant] = $this->provisionOwner();

        [$category, $customTax] = app(CurrentTenant::class)->run(
            $tenant,
            fn (): array => [
                $tenant->categories()->create([
                    'name' => 'Comida',
                    'sort_order' => 0,
                    'is_active' => true,
                ]),
                $tenant->taxes()->create([
                    'name' => 'IVA 8% manual',
                    'code' => 'iva',
                    'factor_type' => 'rate',
                    'rate' => '8.0000',
                    'is_active' => true,
                ]),
            ],
        );

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->getJson('/api/settings/taxes')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonMissing(['id' => $customTax->public_id]);

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->postJson('/api/pos/products', [
                'category_id' => $category->public_id,
                'name' => 'Café',
                'price' => '50.00',
                'tax_id' => $customTax->public_id,
                'is_active' => true,
            ])
            ->assertNotFound();
    }

    /**
     * @return array{User, Tenant}
     */
    private function provisionOwner(
        string $email = 'owner@example.test',
        string $business = 'Principal',
    ): array {
        $this->seed([
            PlanSeeder::class,
            RoleSeeder::class,
        ]);

        $owner = User::factory()->create([
            'email' => $email,
            'email_verified_at' => now(),
        ]);

        $plan = Plan::query()
            ->where('code', PlanCode::Free->value)
            ->sole();

        $tenant = app(CreateTenant::class)->handle(
            $owner,
            $plan,
            $business,
            str($business)->slug()->toString(),
        );

        return [$owner, $tenant];
    }
}
