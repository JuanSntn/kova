<?php

namespace Tests\Feature\Auth;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Enums\RoleName;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StaffUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_password_validation_is_returned_in_spanish(): void
    {
        [$owner, $tenant] = $this->owner('password-validation');
        $branch = app(CurrentTenant::class)->run($tenant, fn () => $tenant->branches()->sole());

        $response = $this->actingAs($owner)->withHeader('X-Tenant-ID', $tenant->public_id)
            ->postJson('/api/settings/users', [
                'name' => 'Pedro López',
                'username' => 'pedro',
                'password' => 'debil',
                'password_confirmation' => 'debil',
                'role' => RoleName::Waiter->value,
                'branch_ids' => [$branch->public_id],
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.password.0', 'La contraseña debe tener al menos 8 caracteres.');

        $this->assertStringNotContainsString('validation.', $response->getContent());
    }

    public function test_owner_creates_staff_and_staff_logs_in_through_assigned_branch(): void
    {
        [$owner, $tenant] = $this->owner('equipo');
        $branch = app(CurrentTenant::class)->run($tenant, fn () => $tenant->branches()->sole());

        $this->actingAs($owner)->withHeader('X-Tenant-ID', $tenant->public_id)
            ->postJson('/api/settings/users', [
                'name' => 'Pedro López',
                'username' => 'PEDRO',
                'password' => 'KovaPass1',
                'password_confirmation' => 'KovaPass1',
                'role' => RoleName::Waiter->value,
                'branch_ids' => [$branch->public_id],
            ])->assertCreated();

        $membership = $tenant->memberships()->where('login_name_normalized', 'pedro')->with('user')->sole();
        $this->assertNull($membership->user->email);
        $this->assertSame('tenant_local', $membership->user->account_type->value);

        $this->withHeader('Origin', config('app.frontend_url'))
            ->postJson('/api/auth/staff/'.$branch->staff_login_code.'/login', [
            'username' => 'pedro',
            'password' => 'KovaPass1',
            ])->assertOk()
            ->assertJsonPath('user.account_type', 'tenant_local')
            ->assertJsonPath('tenants.0.initial_branch_id', $branch->public_id)
            ->assertJsonMissingPath('tenants.0.id_internal');

        $this->withHeader('Origin', config('app.frontend_url'))->getJson('/api/auth/me')->assertOk();
    }

    public function test_staff_login_does_not_reveal_credentials_or_branch_assignment(): void
    {
        [$owner, $tenant] = $this->owner('seguridad');
        $branch = app(CurrentTenant::class)->run($tenant, fn () => $tenant->branches()->sole());
        $other = app(CurrentTenant::class)->run($tenant, fn () => $tenant->branches()->create([
            'code' => 'DOS', 'name' => 'Dos', 'billing_enabled' => true,
        ]));
        $response = $this->actingAs($owner)->withHeader('X-Tenant-ID', $tenant->public_id)
            ->postJson('/api/settings/users', [
                'name' => 'Ana', 'username' => 'ana', 'password' => 'KovaPass1',
                'password_confirmation' => 'KovaPass1', 'role' => 'cashier',
                'branch_ids' => [$branch->public_id],
            ])->assertCreated();
        $this->postJson('/api/auth/logout');

        foreach ([
            [$branch->staff_login_code, 'ana', 'incorrecta'],
            [$other->staff_login_code, 'ana', 'KovaPass1'],
            [$branch->staff_login_code, 'nadie', 'KovaPass1'],
        ] as [$code, $username, $password]) {
            $this->postJson('/api/auth/staff/'.$code.'/login', compact('username', 'password'))
                ->assertUnprocessable()
                ->assertExactJson(['message' => 'Usuario o contraseña incorrectos.', 'code' => 'invalid_credentials']);
        }
        $this->assertNotNull($response);
    }

    public function test_username_is_unique_per_tenant_but_can_repeat_in_another_tenant(): void
    {
        [$firstOwner, $first] = $this->owner('primero');
        [$secondOwner, $second] = $this->owner('segundo');

        foreach ([[$firstOwner, $first], [$secondOwner, $second]] as [$owner, $tenant]) {
            $branch = app(CurrentTenant::class)->run($tenant, fn () => $tenant->branches()->sole());
            $this->actingAs($owner)->withHeader('X-Tenant-ID', $tenant->public_id)
                ->postJson('/api/settings/users', [
                    'name' => 'Pedro', 'username' => 'pedro', 'password' => 'KovaPass1',
                    'password_confirmation' => 'KovaPass1', 'role' => 'waiter',
                    'branch_ids' => [$branch->public_id],
                ])->assertCreated();
        }

        $branch = app(CurrentTenant::class)->run($first, fn () => $first->branches()->sole());
        $this->actingAs($firstOwner)->withHeader('X-Tenant-ID', $first->public_id)
            ->postJson('/api/settings/users', [
                'name' => 'Otro Pedro', 'username' => 'PEDRO', 'password' => 'KovaPass1',
                'password_confirmation' => 'KovaPass1', 'role' => 'waiter',
                'branch_ids' => [$branch->public_id],
            ])->assertUnprocessable()->assertJsonValidationErrors('username');
    }

    public function test_owner_can_edit_and_remove_staff_without_losing_operational_history(): void
    {
        [$owner, $tenant] = $this->owner('editar-eliminar');
        $branch = app(CurrentTenant::class)->run($tenant, fn () => $tenant->branches()->sole());

        $this->actingAs($owner)->withHeader('X-Tenant-ID', $tenant->public_id)
            ->postJson('/api/settings/users', [
                'name' => 'Ana Original', 'username' => 'ana', 'password' => 'KovaPass1',
                'password_confirmation' => 'KovaPass1', 'role' => RoleName::Waiter->value,
                'branch_ids' => [$branch->public_id],
            ])->assertCreated();

        $membership = $tenant->memberships()->where('login_name_normalized', 'ana')->with('user')->sole();

        $this->actingAs($owner)->withHeader('X-Tenant-ID', $tenant->public_id)
            ->patchJson('/api/settings/users/'.$membership->public_id, [
                'name' => 'Ana Editada', 'username' => 'ana-caja', 'role' => RoleName::Cashier->value,
            ])->assertOk()->assertJsonPath('data.id', $membership->public_id);

        $membership->refresh()->load(['user', 'branches']);
        $this->assertSame('Ana Editada', $membership->user->name);
        $this->assertSame('ana-caja', $membership->login_name_normalized);

        $this->actingAs($owner)->withHeader('X-Tenant-ID', $tenant->public_id)
            ->deleteJson('/api/settings/users/'.$membership->public_id)
            ->assertOk()->assertJsonPath('message', 'Usuario eliminado del equipo.');

        $this->actingAs($owner)->withHeader('X-Tenant-ID', $tenant->public_id)
            ->postJson('/api/settings/users/'.$membership->public_id.'/activate')
            ->assertNotFound();

        $this->postJson('/api/auth/staff/'.$branch->staff_login_code.'/login', [
            'username' => 'ana-caja',
            'password' => 'KovaPass1',
        ])->assertUnprocessable();

        $membership->refresh()->load('branches');
        $this->assertSame('archived', $membership->status->value);
        $this->assertNull($membership->login_name);
        $this->assertNull($membership->login_name_normalized);
        $this->assertCount(0, $membership->branches);
        $this->assertDatabaseHas('users', ['id' => $membership->user_id, 'name' => 'Ana Editada']);

        $this->actingAs($owner)->withHeader('X-Tenant-ID', $tenant->public_id)
            ->getJson('/api/settings/users')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($owner)->withHeader('X-Tenant-ID', $tenant->public_id)
            ->postJson('/api/settings/users', [
                'name' => 'Nueva Ana', 'username' => 'ana-caja', 'password' => 'KovaPass1',
                'password_confirmation' => 'KovaPass1', 'role' => RoleName::Waiter->value,
                'branch_ids' => [$branch->public_id],
            ])->assertCreated();
    }

    public function test_staff_management_routes_and_branch_assignments_are_tenant_scoped(): void
    {
        [$owner, $tenant] = $this->owner('equipo-uno');
        [, $otherTenant] = $this->owner('equipo-dos');
        $branch = app(CurrentTenant::class)->run($tenant, fn () => $tenant->branches()->sole());
        $otherBranch = app(CurrentTenant::class)->run($otherTenant, fn () => $otherTenant->branches()->sole());

        $this->actingAs($owner)->withHeader('X-Tenant-ID', $tenant->public_id)
            ->postJson('/api/settings/users', [
                'name' => 'Carlos', 'username' => 'carlos', 'password' => 'KovaPass1',
                'password_confirmation' => 'KovaPass1', 'role' => RoleName::Waiter->value,
                'branch_ids' => [$branch->public_id],
            ])->assertCreated();

        $membership = $tenant->memberships()->where('login_name_normalized', 'carlos')->sole();
        $otherMembership = $otherTenant->memberships()->firstOrFail();
        $request = fn () => $this->actingAs($owner)->withHeader('X-Tenant-ID', $tenant->public_id);

        $request()->patchJson('/api/settings/users/'.$otherMembership->public_id, ['name' => 'No tocar'])->assertNotFound();
        $request()->postJson('/api/settings/users/'.$otherMembership->public_id.'/suspend')->assertNotFound();
        $request()->postJson('/api/settings/users/'.$otherMembership->public_id.'/activate')->assertNotFound();
        $request()->putJson('/api/settings/users/'.$otherMembership->public_id.'/branches', ['branch_ids' => [$branch->public_id]])->assertNotFound();
        $request()->putJson('/api/settings/users/'.$otherMembership->public_id.'/password', ['password' => 'KovaPass1', 'password_confirmation' => 'KovaPass1'])->assertNotFound();
        $request()->deleteJson('/api/settings/users/'.$otherMembership->public_id)->assertNotFound();

        $request()->putJson('/api/settings/users/'.$membership->public_id.'/branches', ['branch_ids' => [$otherBranch->public_id]])
            ->assertConflict();
        $assignedBranches = app(CurrentTenant::class)->run(
            $tenant,
            fn () => $membership->fresh()->branches()->pluck('public_id')->all(),
        );
        $this->assertSame([$branch->public_id], $assignedBranches);
    }

    public function test_the_five_business_seats_have_distinct_operational_roles(): void
    {
        [$owner, $tenant] = $this->owner('roles-operativos');
        $branch = app(CurrentTenant::class)->run($tenant, fn () => $tenant->branches()->sole());
        $versionId = app(CurrentTenant::class)->run(
            $tenant,
            fn () => $tenant->planSubscriptions()->where('current_tenant_id', $tenant->id)->value('plan_version_id'),
        );
        DB::table('plan_entitlements')
            ->where('plan_version_id', $versionId)
            ->where('feature_id', Feature::where('code', 'allows_preparation')->value('id'))
            ->update(['value' => 1, 'is_unlimited' => false]);

        $expectedNavigation = [
            'cashier' => ['dashboard', 'pos', 'cash'],
            'waiter' => ['dashboard', 'pos'],
            'kitchen' => ['dashboard', 'preparation'],
            'bar' => ['dashboard', 'preparation'],
        ];

        foreach ($expectedNavigation as $role => $expectedKeys) {
            $username = 'usuario-'.$role;
            $this->actingAs($owner)->withHeader('X-Tenant-ID', $tenant->public_id)
                ->postJson('/api/settings/users', [
                    'name' => ucfirst($role),
                    'username' => $username,
                    'password' => 'KovaPass1',
                    'password_confirmation' => 'KovaPass1',
                    'role' => $role,
                    'branch_ids' => [$branch->public_id],
                ])->assertCreated();

            $staff = $tenant->memberships()
                ->where('login_name_normalized', $username)
                ->with('user')
                ->sole()
                ->user;
            $response = $this->actingAs($staff)
                ->withHeader('X-Tenant-ID', $tenant->public_id)
                ->getJson('/api/navigation')
                ->assertOk();
            $keys = collect($response->json('sections'))
                ->flatMap(fn (array $section) => collect($section['items'])->pluck('key'))
                ->values()
                ->all();

            $this->assertSame($expectedKeys, $keys, "El rol {$role} no recibió su navegación operativa esperada.");
        }

        $this->assertSame(5, $tenant->memberships()->count());
    }

    private function owner(string $slug): array
    {
        $this->seed([PlanSeeder::class, RoleSeeder::class]);
        $owner = User::factory()->create(['email' => $slug.'@example.test']);
        $tenant = app(CreateTenant::class)->handle($owner, Plan::where('code', 'free')->sole(), ucfirst($slug), $slug);
        $versionId = app(CurrentTenant::class)->run($tenant, fn () => $tenant->planSubscriptions()->where('current_tenant_id', $tenant->id)->value('plan_version_id'));
        $featureId = Feature::where('code', 'max_users')->value('id');
        DB::table('plan_entitlements')->where('plan_version_id', $versionId)->where('feature_id', $featureId)->update(['value' => 5]);
        $branchFeature = Feature::where('code', 'included_branches')->value('id');
        DB::table('plan_entitlements')->where('plan_version_id', $versionId)->where('feature_id', $branchFeature)->update(['value' => 3]);

        return [$owner, $tenant];
    }
}
