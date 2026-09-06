<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\TenantTicketSetting;
use App\Models\User;

class TenantTicketSettingPolicy
{
    public function viewAny(
        User $user
    ): bool {
        return $user->can(
            PermissionName::TicketSettingsView->value
        );
    }

    public function update(
        User $user,
        TenantTicketSetting $setting,
    ): bool {
        return $user->can(
            PermissionName::TicketSettingsManage->value
        );
    }
}
