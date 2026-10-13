<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_subscriptions', fn (Blueprint $t) => $t->json('cancellation_branch_ids')->nullable());
    }

    public function down(): void
    {
        Schema::table('plan_subscriptions', fn (Blueprint $t) => $t->dropColumn('cancellation_branch_ids'));
    }
};
