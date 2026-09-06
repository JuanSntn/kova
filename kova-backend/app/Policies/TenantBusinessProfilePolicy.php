<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\TenantBusinessProfile;
use App\Models\User;

class TenantBusinessProfilePolicy
{
    public function viewAny(
        User $user
    ): bool {
        return $user->can(
            PermissionName::BusinessSettingsView->value
        );
    }

    public function update(
        User $user,
        TenantBusinessProfile $profile,
    ): bool {
        return $user->can(
            PermissionName::BusinessSettingsManage->value
        );
    }
}
