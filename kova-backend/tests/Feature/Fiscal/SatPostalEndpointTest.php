<?php

namespace Tests\Feature\Fiscal;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Models\Plan;
use App\Models\SatCatalogVersion;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SatPostalEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_tenant_resolves_expected_postal_timezones(): void
    {
        [$owner, $tenant] = $this->provisionOwner();
        $this->seedPostalCodes();

        foreach ($this->expectedPostalCodes() as $code => $expected) {
            $this->actingAs($owner)
                ->withHeader('X-Tenant-ID', $tenant->public_id)
                ->getJson('/api/sat/catalogs/postal-codes/'.$code)
                ->assertOk()
                ->assertExactJson([
                    'data' => $expected,
                    'meta' => [
                        'catalog_version' => 'v10.16.20260903',
                    ],
                ]);
        }
    }

    public function test_unknown_and_malformed_postal_codes_return_not_found(): void
    {
        [$owner, $tenant] = $this->provisionOwner();

        foreach (['00000', '123'] as $code) {
            $this->actingAs($owner)
                ->withHeader('X-Tenant-ID', $tenant->public_id)
                ->getJson('/api/sat/catalogs/postal-codes/'.$code)
                ->assertNotFound();
        }
    }

    public function test_postal_catalog_requires_authentication_and_current_tenant(): void
    {
        [, $tenant] = $this->provisionOwner();

        $this->withHeader('X-Tenant-ID', $tenant->public_id)
            ->getJson('/api/sat/catalogs/postal-codes/80290')
            ->assertUnauthorized();
    }

    private function provisionOwner(): array
    {
        $this->seed([PlanSeeder::class, RoleSeeder::class]);
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $plan = Plan::query()->where('code', 'free')->firstOrFail();
        $tenant = app(CreateTenant::class)->handle($owner, $plan, 'Kova', 'kova-postal-test');

        return [$owner, $tenant];
    }

    private function seedPostalCodes(): void
    {
        $versionId = SatCatalogVersion::query()->where('active_slot', 1)->value('id');

        foreach ($this->expectedPostalCodes() as $code => $expected) {
            DB::table('sat_postal_codes')->insertOrIgnore([
                'catalog_version_id' => $versionId,
                'code' => $code,
                'description' => $code,
                'normalized_description' => $code,
                'valid_from' => '2019-01-07',
                'valid_until' => null,
                'state' => $expected['state'],
                'municipality' => $expected['municipality'],
                'locality' => $expected['locality'],
                'timezone_description' => match ((string) $code) {
                    '06000' => 'Tiempo del Centro',
                    '77500' => 'Tiempo del Sureste',
                    '22000' => 'Tiempo del Noroeste',
                    '80290' => 'Tiempo del Pacífico',
                },
            ]);
        }
    }

    private function expectedPostalCodes(): array
    {
        return [
            '06000' => [
                'code' => '06000',
                'description' => '06000',
                'state' => 'CMX',
                'municipality' => '015',
                'locality' => '06',
                'timezone' => 'America/Mexico_City',
            ],
            '77500' => [
                'code' => '77500',
                'description' => '77500',
                'state' => 'ROO',
                'municipality' => '005',
                'locality' => '01',
                'timezone' => 'America/Cancun',
            ],
            '22000' => [
                'code' => '22000',
                'description' => '22000',
                'state' => 'BCN',
                'municipality' => '004',
                'locality' => '04',
                'timezone' => 'America/Tijuana',
            ],
            '80290' => [
                'code' => '80290',
                'description' => '80290',
                'state' => 'SIN',
                'municipality' => '006',
                'locality' => '02',
                'timezone' => 'America/Mazatlan',
            ],
        ];
    }
}
