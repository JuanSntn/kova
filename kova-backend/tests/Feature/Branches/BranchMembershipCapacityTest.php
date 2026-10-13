<?php

namespace Tests\Feature\Branches;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Enums\MembershipStatus;
use App\Exceptions\PlanLimitExceededException;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Memberships\MembershipService;
use App\Tenancy\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchMembershipCapacityTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitation_does_not_consume_capacity_but_activation_does(): void
    {
        [$owner, $tenant] = $this->owner('capacidad');
        $invitedUser = User::factory()->create(['email_verified_at' => now()]);
        $invitation = Membership::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $invitedUser->id,
            'status' => MembershipStatus::Invited,
        ]);

        $this->assertSame(1, $tenant->memberships()->where('status', MembershipStatus::Active->value)->count());

        try {
            app(CurrentTenant::class)->run($tenant, fn () => app(MembershipService::class)->activate($invitation));
            $this->fail('La activación debió respetar max_users.');
        } catch (PlanLimitExceededException) {
            $this->assertSame(MembershipStatus::Invited, $invitation->fresh()->status);
        }

        $tenant->memberships()->where('user_id', $owner->id)->update(['status' => MembershipStatus::Suspended->value]);
        $activated = app(CurrentTenant::class)->run($tenant, fn () => app(MembershipService::class)->activate($invitation));

        $this->assertSame(MembershipStatus::Active, $activated->status);
        $this->assertSame(1, $tenant->memberships()->where('status', MembershipStatus::Active->value)->count());
    }

    public function test_owner_assigns_all_kinds_of_branches_without_consuming_user_capacity(): void
    {
        [$owner, $tenant] = $this->owner('asignaciones');
        $memberUser = User::factory()->create(['email_verified_at' => now()]);
        $membership = Membership::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $memberUser->id,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        $disabled = app(CurrentTenant::class)->run($tenant, fn () => Branch::query()->create([
            'code' => 'HIST',
            'name' => 'Histórica',
            'is_active' => false,
            'billing_enabled' => false,
        ]));
        $ids = $tenant->branches()->withoutGlobalScopes()->pluck('public_id')->all();
        $activeBefore = $tenant->memberships()->where('status', MembershipStatus::Active->value)->count();

        $this->tenant($owner, $tenant)
            ->putJson('/api/settings/memberships/'.$membership->public_id.'/branches', ['branch_ids' => $ids])
            ->assertOk()
            ->assertJsonCount(2, 'data.branch_ids');

        $this->assertTrue(app(CurrentTenant::class)->run($tenant, fn () => $membership->branches()->whereKey($disabled->id)->exists()));
        $this->assertSame($activeBefore, $tenant->memberships()->where('status', MembershipStatus::Active->value)->count());
    }

    public function test_membership_assignment_and_branch_rehabilitation_are_tenant_scoped_and_capacity_checked(): void
    {
        [$owner, $tenant] = $this->owner('uno');
        [, $otherTenant] = $this->owner('dos');
        $otherMembership = $otherTenant->memberships()->firstOrFail();

        $this->tenant($owner, $tenant)
            ->putJson('/api/settings/memberships/'.$otherMembership->public_id.'/branches', ['branch_ids' => []])
            ->assertNotFound();

        $first = app(CurrentTenant::class)->run($tenant, fn () => Branch::query()->sole());
        app(CurrentTenant::class)->run($tenant, fn () => $first->update(['billing_enabled' => false]));
        $this->tenant($owner, $tenant)->postJson('/api/branches/'.$first->public_id.'/enable-for-plan')->assertOk();

        $second = app(CurrentTenant::class)->run($tenant, fn () => Branch::query()->create([
            'code' => 'DOS',
            'name' => 'Dos',
            'billing_enabled' => false,
        ]));
        $this->tenant($owner, $tenant)
            ->postJson('/api/branches/'.$second->public_id.'/enable-for-plan')
            ->assertConflict();

        $this->assertFalse((bool) app(CurrentTenant::class)->run($tenant, fn () => $second->fresh()->billing_enabled));
    }

    private function owner(string $slug): array
    {
        $this->seed([PlanSeeder::class, RoleSeeder::class]);
        $user = User::factory()->create(['email' => $slug.'@example.test', 'email_verified_at' => now()]);
        $plan = Plan::query()->where('code', 'free')->sole();

        return [$user, app(CreateTenant::class)->handle($user, $plan, ucfirst($slug), $slug)];
    }

    private function tenant(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user, 'web')->withHeader('X-Tenant-ID', $tenant->public_id);
    }
}
