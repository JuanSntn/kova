<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class CashierAccessSeeder extends Seeder
{
    public function run(): void
    {
        // Only remove sales access from cashier roles; preserve other roles and grants.
        DB::table('role_has_permissions')
            ->whereIn('role_id', DB::table('roles')->select('id')->where('name', 'cashier')->where('guard_name', 'web'))
            ->whereIn('permission_id', DB::table('permissions')->select('id')->where('name', 'orders.view')->where('guard_name', 'web'))
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
