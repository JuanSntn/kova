<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Models\Plan;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ResolveTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth:sanctum', 'tenant'])
            ->get('/_test/tenant-context', fn (CurrentTenant $tenant) => [
                'tenant_id' => $tenant->id(),
            ]);
    }

    public function test_an_active_member_can_resolve_a_tenant(): void
    {
        $this->seed(RoleSeeder::class);

        $owner = User::factory()->create();
        $tenant = app(CreateTenant::class)->handle(
            $owner,
            Plan::factory()->create(),
            'Café Central',
            'cafe-central',
        );

        $this->actingAs($owner, 'web');

        $this->withHeader('X-Tenant-ID', $tenant->public_id)
            ->getJson('/_test/tenant-context')
            ->assertOk()
            ->assertJsonPath('tenant_id', $tenant->id);

        $this->assertFalse(app(CurrentTenant::class)->isResolved());
        $this->assertNull(getPermissionsTeamId());
    }

    public function test_a_user_cannot_select_a_tenant_without_membership(): void
    {
        $this->seed(RoleSeeder::class);

        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $tenant = app(CreateTenant::class)->handle(
            $owner,
            Plan::factory()->create(),
            'Café Central',
            'cafe-central',
        );

        $this->actingAs($outsider, 'web');

        $this->withHeader('X-Tenant-ID', $tenant->public_id)
            ->getJson('/_test/tenant-context')
            ->assertNotFound();
    }

    public function test_the_tenant_header_is_required(): void
    {
        $this->actingAs(User::factory()->create(), 'web');

        $this->getJson('/_test/tenant-context')
            ->assertBadRequest();
    }

    public function test_an_invalid_tenant_uuid_is_not_found(): void
    {
        $this->actingAs(User::factory()->create(), 'web');

        $this->withHeader('X-Tenant-ID', 'not-a-uuid')
            ->getJson('/_test/tenant-context')
            ->assertNotFound();
    }
}
