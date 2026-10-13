<?php

namespace Tests\Feature\Cash;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Enums\PlanCode;
use App\Models\Branch;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashBranchSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PlanSeeder::class, RoleSeeder::class]);
        $this->owner = User::factory()->create(['email_verified_at' => now()]);
        $this->tenant = app(CreateTenant::class)->handle(
            $this->owner,
            Plan::query()->where('code', PlanCode::Free->value)->sole(),
            'Caja segura',
            'caja-segura',
        );
        $this->branch = app(CurrentTenant::class)->run(
            $this->tenant,
            fn () => $this->tenant->branches()->sole(),
        );
    }

    public function test_free_can_consult_cash_state_but_cannot_create_registers_or_shifts(): void
    {
        $this->asOwner()
            ->getJson('/api/cash/context')
            ->assertOk()
            ->assertJsonPath('data.feature_enabled', false)
            ->assertJsonPath('data.available_actions.open', false);

        $this->asOwner()
            ->postJson('/api/cash/registers', ['name' => 'Caja principal'])
            ->assertConflict()
            ->assertJsonPath('code', 'CASH_FEATURE_UNAVAILABLE');

        $this->asOwner()
            ->postJson('/api/cash/shifts', [
                'register_id' => (string) Str::uuid(),
                'opening_cash' => '0.00',
            ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertConflict()
            ->assertJsonPath('code', 'CASH_FEATURE_UNAVAILABLE');
    }

    public function test_registers_and_shifts_are_recorded_in_the_selected_branch_and_one_user_can_open_one_per_branch(): void
    {
        $this->enableCash();
        $south = $this->createBranch('SUR', 'Sucursal Sur');
        $northRegister = $this->createRegister($this->branch, 'Caja norte');
        $southRegister = $this->createRegister($south, 'Caja sur');

        $northShift = $this->operation($this->branch, 'shifts', [
            'register_id' => $northRegister,
            'opening_cash' => '100.00',
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('cash_shifts', [
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'public_id' => $northShift,
        ]);
        $this->assertDatabaseHas('cash_registers', [
            'tenant_id' => $this->tenant->id,
            'branch_id' => $south->id,
            'public_id' => $southRegister,
        ]);

        $this->asOwner($south)
            ->getJson('/api/cash/context')
            ->assertOk()
            ->assertJsonPath('data.current_shift', null)
            ->assertJsonPath('data.can_checkout', false)
            ->assertJsonPath('data.available_actions.open', true);

        $southShift = $this->operation($south, 'shifts', [
            'register_id' => $southRegister,
            'opening_cash' => '50.00',
        ])->assertCreated()->assertJsonPath('data.branch.id', $south->public_id)->json('data.id');

        $this->asOwner($south)
            ->getJson('/api/cash/context')
            ->assertOk()
            ->assertJsonPath('data.current_shift.id', $southShift)
            ->assertJsonPath('data.current_shift.branch.id', $south->public_id)
            ->assertJsonPath('data.can_checkout', true)
            ->assertJsonPath('data.available_actions.open', false);

        $count = $this->operation($this->branch, "shifts/{$northShift}/counts", ['counted_cash' => '100.00'])
            ->assertCreated()
            ->json('data.id');
        $this->operation($this->branch, "shifts/{$northShift}/close", ['count_id' => $count])
            ->assertCreated()
            ->assertJsonPath('data.status', 'CLOSED');
    }

    public function test_cashier_cannot_view_or_operate_a_branch_without_an_assignment(): void
    {
        $this->enableCash();
        $south = $this->createBranch('SUR', 'Sucursal Sur');
        $northRegister = $this->insertRegister($this->branch, 'Caja norte');
        $southRegister = $this->insertRegister($south, 'Caja sur');
        $cashier = $this->cashierFor($this->branch);

        app('auth')->forgetGuards();
        $this->actingAs($cashier, 'web')->withHeaders([
            'X-Tenant-ID' => $this->tenant->public_id,
            'X-Branch-ID' => $this->branch->public_id,
        ])->getJson('/api/cash/context')
            ->assertOk()
            ->assertJsonPath('data.available_actions.open', true);

        $this->actingAs($cashier, 'web')->withHeaders([
            'X-Tenant-ID' => $this->tenant->public_id,
            'X-Branch-ID' => $south->public_id,
        ])->getJson('/api/cash/context')->assertForbidden();
        $this->actingAs($cashier, 'web')->withHeaders([
            'X-Tenant-ID' => $this->tenant->public_id,
            'X-Branch-ID' => $south->public_id,
        ])->getJson('/api/cash/registers')->assertForbidden();
        $this->actingAs($cashier, 'web')->withHeaders([
            'X-Tenant-ID' => $this->tenant->public_id,
            'X-Branch-ID' => $south->public_id,
        ])->postJson('/api/cash/shifts', [
            'register_id' => $southRegister,
            'opening_cash' => '0.00',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();

        $this->actingAs($cashier, 'web')->withHeaders([
            'X-Tenant-ID' => $this->tenant->public_id,
            'X-Branch-ID' => $this->branch->public_id,
        ])->postJson('/api/cash/shifts', [
            'register_id' => $northRegister,
            'opening_cash' => '0.00',
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()
            ->assertJsonPath('data.branch.id', $this->branch->public_id);
    }

    public function test_cash_endpoints_reject_client_scope_fields_and_foreign_registers(): void
    {
        $this->enableCash();
        $register = $this->createRegister($this->branch, 'Caja principal');

        $this->asOwner()
            ->getJson('/api/cash/shifts?tenant_id=999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');
        $this->asOwner()
            ->postJson('/api/cash/registers', [
                'name' => 'Caja inyectada',
                'tenant_id' => 999,
                'branch_id' => (string) Str::uuid(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tenant_id', 'branch_id']);
        $this->asOwner()
            ->postJson('/api/cash/shifts', [
                'register_id' => $register,
                'opening_cash' => '0.00',
                'branch_id' => (string) Str::uuid(),
                'responsible_user_id' => 999,
            ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id', 'responsible_user_id']);

        $otherOwner = User::factory()->create(['email_verified_at' => now()]);
        $otherTenant = app(CreateTenant::class)->handle(
            $otherOwner,
            Plan::query()->where('code', PlanCode::Free->value)->sole(),
            'Caja ajena',
            'caja-ajena',
        );
        $foreignRegister = app(CurrentTenant::class)->run($otherTenant, function () use ($otherTenant): string {
            $branch = $otherTenant->branches()->sole();

            return $this->insertRegister($branch, 'Caja ajena');
        });

        $this->operation($this->branch, 'shifts', [
            'register_id' => $foreignRegister,
            'opening_cash' => '0.00',
        ])->assertNotFound();
    }

    private function enableCash(): void
    {
        $versionId = DB::table('plan_subscriptions')
            ->where('current_tenant_id', $this->tenant->id)
            ->value('plan_version_id');

        DB::table('plan_entitlements')
            ->where('plan_version_id', $versionId)
            ->where('feature_id', Feature::query()->where('code', 'max_users')->value('id'))
            ->update(['value' => 5, 'is_unlimited' => false]);
    }

    private function createBranch(string $code, string $name): Branch
    {
        return app(CurrentTenant::class)->run(
            $this->tenant,
            fn () => $this->tenant->branches()->create([
                'code' => $code,
                'name' => $name,
                'timezone' => 'America/Mexico_City',
                'is_active' => true,
                'billing_enabled' => true,
            ]),
        );
    }

    private function createRegister(Branch $branch, string $name): string
    {
        return $this->asOwner($branch)
            ->postJson('/api/cash/registers', ['name' => $name])
            ->assertCreated()
            ->json('data.id');
    }

    private function insertRegister(Branch $branch, string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('cash_registers')->insert([
            'public_id' => $id,
            'tenant_id' => $branch->tenant_id,
            'branch_id' => $branch->id,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function cashierFor(Branch $branch): User
    {
        $cashier = User::factory()->create(['email_verified_at' => now()]);
        $membershipId = DB::table('memberships')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'user_id' => $cashier->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('branch_memberships')->insert([
            'membership_id' => $membershipId,
            'branch_id' => $branch->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        setPermissionsTeamId($this->tenant->id);
        $cashier->assignRole('cashier');
        setPermissionsTeamId(null);

        return $cashier;
    }

    private function operation(Branch $branch, string $path, array $data)
    {
        return $this->asOwner($branch)->postJson('/api/cash/'.$path, $data, [
            'Idempotency-Key' => (string) Str::uuid(),
        ]);
    }

    private function asOwner(?Branch $branch = null): static
    {
        return $this->actingAs($this->owner, 'web')->withHeaders([
            'X-Tenant-ID' => $this->tenant->public_id,
            'X-Branch-ID' => ($branch ?? $this->branch)->public_id,
        ]);
    }
}
