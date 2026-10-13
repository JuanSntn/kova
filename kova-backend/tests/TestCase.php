<?php

namespace Tests;

use App\Models\Feature;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (Schema::hasTable('sat_catalog_versions') && ! DB::table('sat_catalog_versions')->where('active_slot', 1)->exists()) {
            $version = DB::table('sat_catalog_versions')->insertGetId(['version' => 'v10.16.20260903', 'status' => 'active', 'active_slot' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $base = fn (string $code, string $description) => ['catalog_version_id' => $version, 'code' => $code, 'description' => $description, 'normalized_description' => strtolower($description), 'valid_from' => '2022-01-01', 'valid_until' => null];
            DB::table('sat_tax_regimes')->insert([[...$base('601', 'General de Ley Personas Morales'), 'applies_to_physical' => false, 'applies_to_legal' => true], [...$base('626', 'Régimen Simplificado de Confianza'), 'applies_to_physical' => true, 'applies_to_legal' => true], [...$base('616', 'Sin obligaciones fiscales'), 'applies_to_physical' => true, 'applies_to_legal' => true]]);
            DB::table('sat_postal_codes')->insert([...$base('42501', 'Hidalgo'), 'state' => 'Hidalgo', 'municipality' => null, 'locality' => null]);
            DB::table('sat_product_services')->insert([...$base('01010101', 'No existe en el catálogo'), 'vat_transfer' => null, 'ieps_transfer' => null]);
            DB::table('sat_units')->insert([...$base('H87', 'Pieza'), 'details' => null, 'symbol' => null]);
            DB::table('sat_tax_objects')->insert($base('02', 'Sí objeto de impuesto'));
            DB::table('sat_cfdi_uses')->insert([...$base('G03', 'Gastos en general'), 'applies_to_physical' => true, 'applies_to_legal' => true, 'receiver_regime_codes' => json_encode(['601', '626', '616'])]);
        }
    }

    public function createApplication()
    {
        // Never allow a cached development configuration to override phpunit's isolated connection.
        putenv('APP_ROUTES_CACHE='.__DIR__.'/../storage/framework/testing-routes-does-not-exist.php');
        putenv('APP_CONFIG_CACHE='.__DIR__.'/../storage/framework/testing-config-does-not-exist.php');
        $app = parent::createApplication();
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('database.connections.sqlite.url', null);
        $app['db']->purge('sqlite');
        if ($app['db']->connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('Tests must use an isolated in-memory database.');
        }

        return $app;
    }

    protected function enableTenantFeature(Tenant $tenant, string $featureCode): void
    {
        $versionId = DB::table('plan_subscriptions')
            ->where('current_tenant_id', $tenant->id)
            ->value('plan_version_id');
        $featureId = Feature::query()->where('code', $featureCode)->value('id');

        DB::table('plan_entitlements')
            ->where('plan_version_id', $versionId)
            ->where('feature_id', $featureId)
            ->update(['value' => 1, 'is_unlimited' => false]);
    }
}
