<?php

namespace App\Services\Auth;

use App\Enums\MembershipStatus;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\User;

final class SessionPayloadService
{
    public function build(User $user, ?string $initialBranchId = null): array
    {
        $tenants = $user->tenants()
            ->wherePivot('status', MembershipStatus::Active->value)
            ->where('tenants.status', 'active')
            ->orderBy('tenants.name')
            ->get(['tenants.id', 'tenants.public_id', 'tenants.name', 'tenants.slug']);
        $previous = getPermissionsTeamId();

        try {
            $tenantPayload = $tenants->map(function ($tenant) use ($user, $initialBranchId): array {
                setPermissionsTeamId($tenant->id);
                $user->unsetRelation('roles')->unsetRelation('permissions');
                $membership = $user->memberships()->where('tenant_id', $tenant->id)->firstOrFail();
                $abilities = collect(PermissionName::cases())
                    ->filter(fn (PermissionName $permission) => $user->can($permission->value))
                    ->map(fn (PermissionName $permission) => $permission->value)
                    ->values();
                $branches = $tenant->branches()->where('is_active', true)->where('billing_enabled', true);
                if (! $user->hasRole(RoleName::Owner->value)) {
                    $branches->whereHas('memberships', fn ($query) => $query->whereKey($membership->id));
                }

                return [
                    'id' => $tenant->public_id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'membership_id' => $membership->public_id,
                    'abilities' => $abilities,
                    'can_select_branch' => ! $user->hasRole(RoleName::Cashier->value) || $user->hasRole(RoleName::Owner->value),
                    'branches' => $branches->orderBy('name')->get()->map(fn ($branch) => [
                        'id' => $branch->public_id,
                        'code' => $branch->code,
                        'name' => $branch->name,
                        'timezone' => $branch->timezone,
                    ])->values(),
                    'initial_branch_id' => $initialBranchId,
                ];
            })->values();

            return [
                'user' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                    'account_type' => $user->effectiveAccountType()->value,
                ],
                'tenants' => $tenantPayload,
            ];
        } finally {
            $user->unsetRelation('roles')->unsetRelation('permissions');
            setPermissionsTeamId($previous);
        }
    }
}
