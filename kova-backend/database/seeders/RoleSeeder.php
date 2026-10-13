<?php

namespace Database\Seeders;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        setPermissionsTeamId(null);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect();

        foreach (PermissionName::cases() as $permissionName) {
            $permissions->push(
                Permission::findOrCreate(
                    $permissionName->value,
                    'web',
                )
            );
        }

        foreach (RoleName::cases() as $roleName) {
            $role = Role::findOrCreate($roleName->value, 'web');

            $names = match ($roleName) {
                RoleName::Owner => $permissions->pluck('name')->all(),
                RoleName::Cashier => [PermissionName::CashView->value, PermissionName::CashOperate->value, PermissionName::OrdersCreate->value],
                RoleName::Waiter => [PermissionName::OrdersCreate->value],
                RoleName::Kitchen, RoleName::Bar => [PermissionName::PreparationView->value, PermissionName::PreparationManage->value],
                default => [],
            };
            $role->syncPermissions($names);
        }
    }
}
