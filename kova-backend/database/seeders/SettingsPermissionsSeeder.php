<?php

namespace Database\Seeders;

use App\Enums\PermissionName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SettingsPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(
            PermissionRegistrar::class
        )->forgetCachedPermissions();

        $permissions = [
            PermissionName::BusinessSettingsView,
            PermissionName::BusinessSettingsManage,
            PermissionName::TicketSettingsView,
            PermissionName::TicketSettingsManage,
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate(
                $permission->value,
                'web',
            );
        }

        /*
         * Owner puede administrar todo.
         *
         * Admin también puede administrar
         * configuración del negocio/ticket.
         *
         * Cajero, Mesero, Cocina y Bar no.
         */
        Role::query()
            ->whereIn(
                'name',
                [
                    'owner',
                    'admin',
                ]
            )
            ->get()
            ->each(
                function (Role $role) use (
                    $permissions
                ): void {
                    $role->givePermissionTo(
                        collect($permissions)
                            ->map(
                                fn(
                                    PermissionName $permission
                                ) =>
                                $permission->value
                            )
                            ->all()
                    );
                }
            );

        app(
            PermissionRegistrar::class
        )->forgetCachedPermissions();
    }
}
