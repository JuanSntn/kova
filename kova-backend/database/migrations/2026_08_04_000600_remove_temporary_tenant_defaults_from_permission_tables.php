<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableNames = config('permission.table_names');
        $tenantKey = config('permission.column_names.team_foreign_key');

        foreach (['model_has_permissions', 'model_has_roles'] as $tableName) {
            Schema::table($tableNames[$tableName], function (Blueprint $table) use ($tenantKey) {
                $table->unsignedBigInteger($tenantKey)->change();
            });
        }
    }

    public function down(): void
    {
        // Restoring a tenant default would allow unscoped assignments.
    }
};
