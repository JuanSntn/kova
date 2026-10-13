<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Enums\RoleName;
use App\Models\Branch;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_provisions_the_tenant_atomically_with_a_snapshot_and_owner_role(): void
    {
        $this->seed(RoleSeeder::class);

        $owner = User::factory()->create();
        $plan = Plan::factory()->create();

        $tenant = app(CreateTenant::class)->handle(
            owner: $owner,
            plan: $plan,
            name: 'Café Central',
            slug: 'cafe-central',
        );

        $this->assertDatabaseHas('memberships', [
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'status' => 'active',
        ]);

        app(CurrentTenant::class)->run($tenant, function () use ($owner): void {
            setPermissionsTeamId(app(CurrentTenant::class)->id());
            $owner->unsetRelation('roles');

            $this->assertTrue($owner->hasRole(RoleName::Owner));
            $this->assertSame(1, Branch::query()->count());

            $subscription = PlanSubscription::query()->sole();

            $this->assertSame('0.00', $subscription->monthly_price_snapshot);
            $this->assertSame(5, $subscription->entitlements_snapshot['max_users']);
            $this->assertSame(50, $subscription->entitlements_snapshot['included_stamps']);
        });

        setPermissionsTeamId(null);

        $plan->update(['name' => 'Renamed catalog identity']);

        app(CurrentTenant::class)->run($tenant, function (): void {
            $subscription = PlanSubscription::query()->sole();

            $this->assertSame('0.00', $subscription->monthly_price_snapshot);
            $this->assertSame(5, $subscription->entitlements_snapshot['max_users']);
        });
    }
}
