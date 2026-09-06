<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_subscriptions', function (Blueprint $table) {
            $table->timestamp('last_stripe_event_created_at')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('plan_subscriptions', function (Blueprint $table) {
            $table->dropColumn('last_stripe_event_created_at');
        });
    }
};
