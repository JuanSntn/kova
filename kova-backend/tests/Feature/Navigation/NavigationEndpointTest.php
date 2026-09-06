<?php

namespace Tests\Feature\Navigation;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Enums\MembershipStatus;
use App\Enums\PlanCode;
use App\Enums\RoleName;
use App\Models\Membership;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_all_navigation_sections(): void
    {
        [$owner, $tenant] = $this->provisionOwner();

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->getJson('/api/navigation')
            ->assertOk()
            ->assertJsonStructure([
                'sections' => [
                    [
                        'key',
                        'label',
                        'order',
                        'items' => [
                            [
                                'key',
                                'label',
                                'path',
                                'icon',
                                'order',
                            ],
                        ],
                    ],
                ],
            ])
            ->assertJsonPath('sections.0.key', 'main')
            ->assertJsonPath('sections.1.key', 'sales')
            ->assertJsonPath('sections.2.key', 'catalog')
            ->assertJsonPath('sections.3.key', 'settings')
            ->assertJsonPath('sections.2.items.0.key', 'menu')
            ->assertJsonPath('sections.3.items.0.key', 'taxes')
            ->assertJsonPath('sections.3.items.1.key', 'billing')
            ->assertJsonMissingPath('sections.0.items.0.permission');
    }

    public function test_cashier_sees_only_unrestricted_navigation_items(): void
    {
        [$owner, $tenant] = $this->provisionOwner();
        $cashier = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        app(CurrentTenant::class)->run($tenant, function () use ($cashier, $tenant): void {
            Membership::query()->create([
                'tenant_id' => $tenant->getKey(),
                'user_id' => $cashier->getKey(),
                'status' => MembershipStatus::Active,
                'joined_at' => now(),
            ]);

            setPermissionsTeamId($tenant->getKey());
            $cashier->assignRole(RoleName::Cashier);
        });
        setPermissionsTeamId(null);

        $this->actingAs($cashier, 'web')
            ->withHeader('X-Tenant-ID', $tenant->public_id)
            ->getJson('/api/navigation')
            ->assertOk()
            ->assertJsonPath('sections.0.key', 'main')
            ->assertJsonPath('sections.1.key', 'sales')
            ->assertJsonPath('sections.0.items.0.key', 'dashboard')
            ->assertJsonPath('sections.1.items.0.key', 'pos')
            ->assertJsonMissingPath('sections.2')
            ->assertJsonMissingPath('sections.3');

        $this->assertNotNull($owner);
    }

    public function test_a_user_cannot_read_navigation_for_a_tenant_without_membership(): void
    {
        [$owner] = $this->provisionOwner();
        [, $otherTenant] = $this->provisionOwner('second@example.test', 'Segundo');

        $this->actingAs($owner, 'web')
            ->withHeader('X-Tenant-ID', $otherTenant->public_id)
            ->getJson('/api/navigation')
            ->assertNotFound();
    }

    /**
     * @return array{User, Tenant}
     */
    private function provisionOwner(
        string $email = 'owner@example.test',
        string $business = 'Principal',
    ): array {
        $this->seed([PlanSeeder::class, RoleSeeder::class]);

        $owner = User::factory()->create([
            'email' => $email,
            'email_verified_at' => now(),
        ]);
        $plan = Plan::query()->where('code', PlanCode::Free->value)->sole();
        $tenant = app(CreateTenant::class)->handle(
            $owner,
            $plan,
            $business,
            str($business)->slug()->toString(),
        );

        return [$owner, $tenant];
    }
}
