<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Tax;
use App\Models\User;

class TaxPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(
            PermissionName::TaxesView->value
        );
    }

    public function view(User $user, Tax $tax): bool
    {
        return $user->can(
            PermissionName::TaxesView->value
        );
    }

    public function create(User $user): bool
    {
        return $user->can(
            PermissionName::TaxesManage->value
        );
    }

    public function update(User $user, Tax $tax): bool
    {
        return $user->can(
            PermissionName::TaxesManage->value
        );
    }
}
