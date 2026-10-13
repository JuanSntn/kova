<?php

namespace App\Services\Branches;

use App\Enums\MembershipStatus;
use App\Enums\RoleName;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;

final class BranchAccessService
{
    public function operableFor(User $user, Tenant $tenant, string $permission): Collection
    {
        $membership = $this->membership($user, $tenant);
        $this->assertPermission($user, $permission);
        $query = $tenant->branches()->where('is_active', true)->where('billing_enabled', true);
        if (! $user->hasRole(RoleName::Owner->value)) {
            $query->whereHas('memberships', fn ($q) => $q->whereKey($membership->id));
        }

        return $query->orderBy('name')->get();
    }

    public function assertOperate(User $user, Tenant $tenant, Branch $branch, string $permission): Membership
    {
        $membership = $this->assertHistory($user, $tenant, $branch, $permission);
        if (! $branch->is_active || ! $branch->billing_enabled) {
            throw new AuthorizationException('La sucursal no está disponible para operar.');
        }

        return $membership;
    }

    public function assertHistory(User $user, Tenant $tenant, Branch $branch, ?string $permission = null): Membership
    {
        if ((int) $branch->tenant_id !== (int) $tenant->id) {
            throw new AuthorizationException;
        }
        $membership = $this->membership($user, $tenant);
        if ($permission !== null) {
            $this->assertPermission($user, $permission);
        }
        if (! $user->hasRole(RoleName::Owner->value) && ! $membership->branches()->whereKey($branch->id)->exists()) {
            throw new AuthorizationException;
        }

        return $membership;
    }

    public function historicalBranchIds(User $user, Tenant $tenant, string $permission): array
    {
        $membership = $this->membership($user, $tenant);
        $this->assertPermission($user, $permission);

        return $user->hasRole(RoleName::Owner->value)
            ? $tenant->branches()->pluck('id')->all()
            : $membership->branches()->pluck('branches.id')->all();
    }

    private function membership(User $user, Tenant $tenant): Membership
    {
        return Membership::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active->value)->firstOrFail();
    }

    private function assertPermission(User $user, string $permission): void
    {
        if (! $user->can($permission)) {
            throw new AuthorizationException;
        }
    }
}
