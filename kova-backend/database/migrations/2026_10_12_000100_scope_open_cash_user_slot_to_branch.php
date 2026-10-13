<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_shifts', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'open_user_id']);
            $table->unique(['tenant_id', 'branch_id', 'open_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('cash_shifts', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'branch_id', 'open_user_id']);
            $table->unique(['tenant_id', 'open_user_id']);
        });
    }
};
