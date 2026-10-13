<?php

namespace App\Services\Memberships;

use App\Enums\AccountType;
use App\Enums\MembershipStatus;
use App\Enums\RoleName;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\SessionInvalidator;
use App\Services\Subscriptions\CapacityService;
use App\Services\Subscriptions\EntitlementService;
use App\Tenancy\CurrentTenant;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class MembershipService
{
    private const STAFF_ROLES = [RoleName::Cashier, RoleName::Waiter, RoleName::Kitchen, RoleName::Bar];

    public function __construct(
        private CapacityService $capacity,
        private MembershipBillingGuard $billing,
        private SessionInvalidator $sessions,
        private EntitlementService $entitlements,
        private CurrentTenant $currentTenant,
    ) {}

    public function createActive(Tenant $tenant, User $user): Membership
    {
        return DB::transaction(function () use ($tenant, $user): Membership {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->assertSeat($tenant);

            return Membership::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'status' => MembershipStatus::Active,
                'joined_at' => now(),
            ]);
        });
    }

    public function createStaff(Tenant $tenant, array $data): Membership
    {
        $this->billing->assertCanExpand($tenant);
        $role = $this->staffRole($data['role']);
        $normalized = self::normalizeLoginName($data['username']);

        return DB::transaction(function () use ($tenant, $data, $role, $normalized): Membership {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->assertSeat($tenant);
            $branches = $this->operableBranches($tenant, $data['branch_ids']);
            $user = User::query()->create([
                'name' => trim($data['name']),
                'account_type' => AccountType::TenantLocal,
                'email' => null,
                'password' => $data['password'],
            ]);
            $membership = Membership::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'login_name' => trim($data['username']),
                'login_name_normalized' => $normalized,
                'status' => MembershipStatus::Active,
                'joined_at' => now(),
            ]);
            $membership->branches()->sync($branches->modelKeys());
            $this->setRole($tenant, $user, $role);

            return $membership->load(['user', 'branches']);
        }, 3);
    }

    public function updateStaff(Membership $membership, array $data): Membership
    {
        $this->assertTenantLocal($membership);
        $tenant = $membership->tenant;
        $this->entitlements->assertWritable($tenant);

        return DB::transaction(function () use ($membership, $tenant, $data): Membership {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $locked = Membership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            $attributes = [];
            if (array_key_exists('username', $data)) {
                $attributes['login_name'] = trim($data['username']);
                $attributes['login_name_normalized'] = self::normalizeLoginName($data['username']);
            }
            if ($attributes !== []) {
                $locked->update($attributes);
            }
            if (array_key_exists('name', $data)) {
                $locked->user->update(['name' => trim($data['name'])]);
            }
            if (array_key_exists('role', $data)) {
                $this->setRole($tenant, $locked->user, $this->staffRole($data['role']));
            }

            return $locked->refresh()->load(['user', 'branches']);
        }, 3);
    }

    public function suspend(Membership $membership): Membership
    {
        $this->assertTenantLocal($membership);

        return DB::transaction(function () use ($membership): Membership {
            Tenant::query()->whereKey($membership->tenant_id)->lockForUpdate()->firstOrFail();
            $locked = Membership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            $locked->update(['status' => MembershipStatus::Suspended]);
            $this->sessions->invalidate($locked->user);

            return $locked->refresh();
        }, 3);
    }

    public function activate(Membership $membership): Membership
    {
        if ($membership->status === MembershipStatus::Active) {
            return $membership;
        }
        $tenant = $membership->tenant;
        $this->billing->assertCanExpand($tenant);

        return DB::transaction(function () use ($membership, $tenant): Membership {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $locked = Membership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === MembershipStatus::Active) {
                return $locked;
            }
            $this->assertSeat($tenant);
            $locked->update(['status' => MembershipStatus::Active, 'joined_at' => $locked->joined_at ?? now()]);

            return $locked->refresh();
        }, 3);
    }

    /**
     * Removes an operational account from the active team without deleting the
     * user row that is referenced by completed sales, cash shifts and audits.
     */
    public function removeStaff(Membership $membership): Membership
    {
        $this->assertTenantLocal($membership);

        return DB::transaction(function () use ($membership): Membership {
            $tenant = $this->currentTenant->get();
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $locked = Membership::query()
                ->whereKey($membership->id)
                ->where('tenant_id', $tenant->id)
                ->lockForUpdate()
                ->firstOrFail();

            $hasOpenShift = DB::table('cash_shifts')
                ->where('tenant_id', $tenant->id)
                ->where('responsible_user_id', $locked->user_id)
                ->where('status', 'OPEN')
                ->exists();

            if ($hasOpenShift) {
                throw new DomainException('No puedes eliminar a este usuario mientras tenga un turno abierto. Cierra el turno primero.');
            }

            $locked->branches()->detach();
            $locked->update([
                'status' => MembershipStatus::Archived,
                'login_name' => null,
                'login_name_normalized' => null,
            ]);
            $this->sessions->invalidate($locked->user);

            return $locked->refresh();
        }, 3);
    }

    public function resetPassword(Membership $membership, string $password): void
    {
        $this->assertTenantLocal($membership);
        $this->entitlements->assertWritable($membership->tenant);
        DB::transaction(function () use ($membership, $password): void {
            $user = User::query()->whereKey($membership->user_id)->lockForUpdate()->firstOrFail();
            $user->update(['password' => Hash::make($password)]);
            $this->sessions->invalidate($user);
        }, 3);
    }

    public function syncBranches(Membership $membership, array $branchPublicIds): Membership
    {
        $this->assertTenantLocal($membership);
        $this->entitlements->assertWritable($membership->tenant);
        $branches = $this->operableBranches($membership->tenant, $branchPublicIds);
        DB::transaction(fn () => $membership->branches()->sync($branches->modelKeys()));

        return $membership->refresh()->load('branches');
    }

    public static function normalizeLoginName(string $value): string
    {
        return Str::lower(trim($value));
    }

    private function assertSeat(Tenant $tenant): void
    {
        $this->capacity->assertAdditional(
            $tenant,
            'max_users',
            $tenant->memberships()->where('status', MembershipStatus::Active->value)->count(),
            message: 'Tu plan alcanzó el límite de usuarios activos.',
        );
    }

    private function operableBranches(Tenant $tenant, array $publicIds)
    {
        $ids = array_values(array_unique($publicIds));
        if ($ids === []) {
            throw new DomainException('Selecciona al menos una sucursal.');
        }
        $branches = $tenant->branches()->whereIn('public_id', $ids)
            ->where('is_active', true)->where('billing_enabled', true)->get();
        if ($branches->count() !== count($ids)) {
            throw new DomainException('Una o más sucursales no están disponibles.');
        }

        return $branches;
    }

    private function assertTenantLocal(Membership $membership): void
    {
        $membership->loadMissing(['user', 'tenant']);
        if ((int) $membership->tenant_id !== $this->currentTenant->id()
            || $membership->user->account_type !== AccountType::TenantLocal
            || $membership->user->memberships()->count() !== 1) {
            throw new DomainException('Esta operación sólo está disponible para cuentas locales del negocio.');
        }
    }

    private function staffRole(string $value): RoleName
    {
        $role = RoleName::tryFrom($value);
        if (! $role || ! in_array($role, self::STAFF_ROLES, true)) {
            throw new DomainException('El rol seleccionado no está disponible para usuarios operativos.');
        }

        return $role;
    }

    private function setRole(Tenant $tenant, User $user, RoleName $role): void
    {
        $previous = getPermissionsTeamId();
        try {
            setPermissionsTeamId($tenant->id);
            $user->unsetRelation('roles')->syncRoles([$role->value]);
        } finally {
            $user->unsetRelation('roles');
            setPermissionsTeamId($previous);
        }
    }
}
