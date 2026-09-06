<?php

namespace App\Policies;

use App\Enums\MembershipStatus;
use App\Enums\RoleName;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\User;
use App\Tenancy\CurrentTenant;

class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasActiveMembership($user);
    }

    public function view(User $user, Branch $branch): bool
    {
        return $this->hasActiveMembership($user)
            && $branch->tenant_id === app(CurrentTenant::class)->id();
    }

    public function create(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function update(User $user, Branch $branch): bool
    {
        return $this->view($user, $branch) && $this->isOwner($user);
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $this->update($user, $branch);
    }

    private function hasActiveMembership(User $user): bool
    {
        $tenant = app(CurrentTenant::class);

        return $tenant->isResolved()
            && Membership::query()
                ->where('tenant_id', $tenant->id())
                ->where('user_id', $user->getKey())
                ->where('status', MembershipStatus::Active->value)
                ->exists();
    }

    private function isOwner(User $user): bool
    {
        return $this->hasActiveMembership($user)
            && $user->hasRole(RoleName::Owner->value);
    }
}
