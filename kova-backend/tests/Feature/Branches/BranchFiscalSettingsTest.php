<?php

namespace Tests\Feature\Branches;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Models\FiscalSeries;
use App\Models\Plan;
use App\Models\TenantFiscalProfile;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchFiscalSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_fiscal_endpoint_returns_real_series_counters(): void
    {
        $this->seed([PlanSeeder::class, RoleSeeder::class]);
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $plan = Plan::query()->where('code', 'free')->sole();
        $tenant = app(CreateTenant::class)->handle($owner, $plan, 'Kova', 'kova');
        $this->enableTenantFeature($tenant, 'allows_invoicing');

        [$branch, $seriesB] = app(CurrentTenant::class)->run($tenant, function () use ($tenant): array {
            TenantFiscalProfile::query()->create([
                'tenant_id' => $tenant->id,
                'status' => 'incomplete',
                'default_series' => 'A',
            ]);
            FiscalSeries::query()->create([
                'tenant_id' => $tenant->id,
                'series' => 'A',
                'next_folio' => 17,
                'is_active' => true,
            ]);
            $seriesB = FiscalSeries::query()->create([
                'tenant_id' => $tenant->id,
                'series' => 'B',
                'next_folio' => 42,
                'is_active' => true,
            ]);

            return [$tenant->branches()->sole(), $seriesB];
        });

        $request = $this->actingAs($owner, 'web')->withHeader('X-Tenant-ID', $tenant->public_id);

        $request->getJson('/api/branches')
            ->assertOk()
            ->assertJsonPath('meta.capabilities.allowsInvoicing', true);

        $request->getJson('/api/settings/branches/'.$branch->public_id.'/fiscal')
            ->assertOk()
            ->assertJsonPath('data.mode', 'inherit')
            ->assertJsonPath('data.default_series', 'A')
            ->assertJsonPath('data.effective_series', 'A')
            ->assertJsonPath('data.effective_next_folio', 17)
            ->assertJsonPath('data.effective_next_number', 'A-000017')
            ->assertJsonPath('data.series.1.series', 'B')
            ->assertJsonPath('data.series.1.next_number', 'B-000042');

        $request->putJson('/api/settings/branches/'.$branch->public_id.'/fiscal', [
            'mode' => 'override',
            'series' => $seriesB->series,
        ])
            ->assertOk()
            ->assertJsonPath('data.mode', 'override')
            ->assertJsonPath('data.effective_series', 'B')
            ->assertJsonPath('data.effective_next_folio', 42)
            ->assertJsonPath('data.effective_next_number', 'B-000042');
    }

    public function test_free_plan_can_manage_its_branch_but_cannot_access_fiscal_series(): void
    {
        $this->seed([PlanSeeder::class, RoleSeeder::class]);
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $plan = Plan::query()->where('code', 'free')->sole();
        $tenant = app(CreateTenant::class)->handle($owner, $plan, 'Kova Free', 'kova-free');
        $branch = app(CurrentTenant::class)->run(
            $tenant,
            fn () => $tenant->branches()->sole(),
        );

        $request = $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id);

        $request->getJson('/api/branches')
            ->assertOk()
            ->assertJsonPath('meta.capabilities.allowsInvoicing', false)
            ->assertJsonPath('data.0.id', $branch->public_id);

        $request->patchJson('/api/branches/'.$branch->public_id, [
            'name' => 'Sucursal principal actualizada',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Sucursal principal actualizada');

        $request->getJson('/api/settings/branches/'.$branch->public_id.'/fiscal')
            ->assertForbidden()
            ->assertJsonPath('message', 'Tu plan actual no incluye configuración de series CFDI.');

        $request->putJson('/api/settings/branches/'.$branch->public_id.'/fiscal', [
            'mode' => 'inherit',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Tu plan actual no incluye configuración de series CFDI.');
    }
}
