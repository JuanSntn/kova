<?php

namespace Tests\Feature\Fiscal;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Models\Plan;
use App\Models\SatCatalogVersion;
use App\Models\User;
use App\Services\Fiscal\RfcClassifier;
use App\Services\Fiscal\SatCatalogService;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SatCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_rfc_classifier_distinguishes_people_and_generic_receivers(): void
    {
        $classifier = app(RfcClassifier::class);
        $this->assertSame('physical', $classifier->classify('GODE561231GR8'));
        $this->assertSame('legal', $classifier->classify('AAA010101AAA'));
        $this->assertSame('generic_national', $classifier->classify('XAXX010101000'));
        $this->assertSame('generic_foreign', $classifier->classify('XEXX010101000'));
        $this->assertSame('invalid', $classifier->classify('INVALIDO'));
    }

    public function test_regime_endpoint_filters_by_rfc_type_and_reports_catalog_version(): void
    {
        [$owner, $tenant] = $this->tenant();
        $this->actingAs($owner)->withHeader('X-Tenant-ID', $tenant->public_id)
            ->getJson('/api/sat/catalogs/tax-regimes?rfc=GODE561231GR8')
            ->assertOk()->assertJsonPath('meta.catalog_version', 'v10.16.20260903')
            ->assertJsonFragment(['code' => '626'])->assertJsonMissing(['code' => '601']);
    }

    public function test_profile_rejects_incompatible_regime_and_client_cannot_choose_unit_name(): void
    {
        [$owner, $tenant] = $this->tenant();
        $this->enableTenantFeature($tenant, 'allows_invoicing');
        $payload = ['rfc' => 'GODE561231GR8', 'legal_name' => 'PERSONA DE PRUEBA', 'tax_regime_code' => '601', 'postal_code' => '42501', 'fiscal_email' => 'fiscal@example.test', 'default_product_service_code' => '01010101', 'default_unit_code' => 'H87', 'default_tax_object_code' => '02'];
        $this->actingAs($owner)->withHeader('X-Tenant-ID', $tenant->public_id)->putJson('/api/settings/fiscal/profile', $payload)->assertUnprocessable()->assertJsonValidationErrors('tax_regime_code');
        $payload['tax_regime_code'] = '626';
        $payload['default_unit_name'] = 'Kilogramo';
        $this->actingAs($owner)->withHeader('X-Tenant-ID', $tenant->public_id)->putJson('/api/settings/fiscal/profile', $payload)->assertUnprocessable()->assertJsonValidationErrors('default_unit_name');
        unset($payload['default_unit_name']);
        $this->actingAs($owner)->withHeader('X-Tenant-ID', $tenant->public_id)->putJson('/api/settings/fiscal/profile', $payload)->assertOk()->assertJsonPath('data.default_unit_name', 'Pieza');
    }

    public function test_catalog_service_rejects_unknown_product_codes(): void
    {
        $this->expectException(ValidationException::class);
        app(SatCatalogService::class)->assertProduct(['sat_product_service_code' => '99999999']);
    }

    public function test_search_normalizes_accents_and_matches_multiple_words(): void
    {
        $version = SatCatalogVersion::query()->where('active_slot', 1)->firstOrFail();
        DB::table('sat_product_services')->insert(['catalog_version_id' => $version->id, 'code' => '90101700', 'description' => 'Servicios de cafetería', 'normalized_description' => 'servicios de cafeteria', 'valid_from' => '2022-01-01', 'valid_until' => null, 'vat_transfer' => true, 'ieps_transfer' => false]);
        $result = app(SatCatalogService::class)->search('sat_product_services', 'servicio cafetería');
        $this->assertSame('90101700', $result[0]['code']);
    }

    public function test_database_allows_only_one_active_catalog_slot(): void
    {
        $this->expectException(QueryException::class);
        SatCatalogVersion::query()->create(['version' => 'v99.1.20990101', 'status' => 'active', 'active_slot' => 1]);
    }

    private function tenant(): array
    {
        $this->seed([PlanSeeder::class, RoleSeeder::class]);
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $plan = Plan::query()->where('code', 'free')->firstOrFail();

        return [$owner, app(CreateTenant::class)->handle($owner, $plan, 'Uno', 'uno')];
    }
}
