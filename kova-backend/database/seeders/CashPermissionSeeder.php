<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Additive deployment: does not revoke any existing permission. */
class CashPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['cash.view', 'cash.operate', 'cash.manage'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        foreach (Role::where('guard_name', 'web')->whereIn('name', ['owner', 'cashier'])->get() as $role) {
            $role->givePermissionTo($role->name === 'owner' ? ['cash.view', 'cash.operate', 'cash.manage'] : ['cash.view', 'cash.operate']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
