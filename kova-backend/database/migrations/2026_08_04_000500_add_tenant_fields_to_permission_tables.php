<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $tenantKey = $columnNames['team_foreign_key'];
        $pivotRole = $columnNames['role_pivot_key'] ?? 'role_id';
        $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';

        if (! Schema::hasColumn($tableNames['roles'], $tenantKey)) {
            Schema::table($tableNames['roles'], function (Blueprint $table) use ($tenantKey) {
                $table->unsignedBigInteger($tenantKey)->nullable();
                $table->index($tenantKey, 'roles_tenant_id_index');
                $table->dropUnique('roles_name_guard_name_unique');
                $table->unique([$tenantKey, 'name', 'guard_name']);
            });
        }

        if (! Schema::hasColumn($tableNames['model_has_permissions'], $tenantKey)) {
            Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) use (
                $tableNames,
                $columnNames,
                $tenantKey,
                $pivotPermission,
            ) {
                $table->unsignedBigInteger($tenantKey)->default(1);
                $table->index($tenantKey, 'model_permissions_tenant_id_index');

                if (DB::getDriverName() !== 'sqlite') {
                    $table->dropForeign([$pivotPermission]);
                }

                $table->dropPrimary();
                $table->primary(
                    [$tenantKey, $pivotPermission, $columnNames['model_morph_key'], 'model_type'],
                    'model_has_permissions_permission_model_type_primary',
                );

                if (DB::getDriverName() !== 'sqlite') {
                    $table->foreign($pivotPermission)
                        ->references('id')
                        ->on($tableNames['permissions'])
                        ->cascadeOnDelete();
                }
            });
        }

        if (! Schema::hasColumn($tableNames['model_has_roles'], $tenantKey)) {
            Schema::table($tableNames['model_has_roles'], function (Blueprint $table) use (
                $tableNames,
                $columnNames,
                $tenantKey,
                $pivotRole,
            ) {
                $table->unsignedBigInteger($tenantKey)->default(1);
                $table->index($tenantKey, 'model_roles_tenant_id_index');

                if (DB::getDriverName() !== 'sqlite') {
                    $table->dropForeign([$pivotRole]);
                }

                $table->dropPrimary();
                $table->primary(
                    [$tenantKey, $pivotRole, $columnNames['model_morph_key'], 'model_type'],
                    'model_has_roles_role_model_type_primary',
                );

                if (DB::getDriverName() !== 'sqlite') {
                    $table->foreign($pivotRole)
                        ->references('id')
                        ->on($tableNames['roles'])
                        ->cascadeOnDelete();
                }
            });
        }

        app('cache')
            ->store(config('permission.cache.store') !== 'default'
                ? config('permission.cache.store')
                : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        // Team columns are intentionally retained because removing them can merge
        // role assignments from different tenants and corrupt authorization data.
    }
};
