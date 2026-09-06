<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Models\Branch;
use App\Models\Plan;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TenantRouteBindingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum', 'verified', 'tenant'])
            ->get('/_test/branches/{branch}', fn (Branch $branch) => [
                'id' => $branch->public_id,
                'name' => $branch->name,
            ]);
    }

    public function test_binding_uses_public_id_after_the_tenant_is_resolved(): void
    {
        $this->seed(RoleSeeder::class);

        $owner = User::factory()->create();
        $tenant = app(CreateTenant::class)->handle(
            $owner,
            Plan::factory()->create(),
            'Café Central',
            'cafe-central',
        );
        $branch = app(CurrentTenant::class)->run(
            $tenant,
            fn () => Branch::query()->sole(),
        );

        $this->actingAs($owner, 'web');

        $this->withHeader('X-Tenant-ID', $tenant->public_id)
            ->getJson('/_test/branches/'.$branch->public_id)
            ->assertOk()
            ->assertJsonPath('id', $branch->public_id);
    }

    public function test_a_branch_from_another_tenant_is_indistinguishable_from_missing(): void
    {
        $this->seed(RoleSeeder::class);

        $owner = User::factory()->create();
        $plan = Plan::factory()->create();
        $firstTenant = app(CreateTenant::class)->handle($owner, $plan, 'Primero', 'primero');
        $secondTenant = app(CreateTenant::class)->handle($owner, $plan, 'Segundo', 'segundo');
        $secondBranch = app(CurrentTenant::class)->run(
            $secondTenant,
            fn () => Branch::query()->sole(),
        );

        $this->actingAs($owner, 'web');

        $this->withHeader('X-Tenant-ID', $firstTenant->public_id)
            ->getJson('/_test/branches/'.$secondBranch->public_id)
            ->assertNotFound();
    }

    public function test_internal_ids_are_not_valid_route_identifiers(): void
    {
        $this->seed(RoleSeeder::class);

        $owner = User::factory()->create();
        $tenant = app(CreateTenant::class)->handle(
            $owner,
            Plan::factory()->create(),
            'Café Central',
            'cafe-central',
        );
        $branch = app(CurrentTenant::class)->run(
            $tenant,
            fn () => Branch::query()->sole(),
        );

        $this->actingAs($owner, 'web');

        $this->withHeader('X-Tenant-ID', $tenant->public_id)
            ->getJson('/_test/branches/'.$branch->getKey())
            ->assertNotFound();
    }
}
