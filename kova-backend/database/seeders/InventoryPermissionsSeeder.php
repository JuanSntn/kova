<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class InventoryPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = collect(['inventory.view', 'inventory.manage'])->map(fn ($name) => Permission::findOrCreate($name, 'web'));
        foreach (Role::where('name', 'owner')->where('guard_name', 'web')->get() as $role) {
            $role->givePermissionTo($permissions);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
