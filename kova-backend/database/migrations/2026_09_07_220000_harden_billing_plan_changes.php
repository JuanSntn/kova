<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table
                ->unsignedSmallInteger('tier')
                ->default(0)
                ->index();
        });

        DB::table('plans')
            ->where('code', 'free')
            ->update(['tier' => 0]);

        DB::table('plans')
            ->where('code', 'business')
            ->update(['tier' => 100]);

        DB::table('plans')
            ->where('code', 'restaurant')
            ->update(['tier' => 200]);

        Schema::table('plan_subscriptions', function (Blueprint $table) {
            $table
                ->timestamp('cancellation_requested_at')
                ->nullable();
        });

        Schema::table('subscription_changes', function (Blueprint $table) {
            $table
                ->string('change_mode')
                ->default('period_end');

            $table
                ->foreignId('target_plan_subscription_id')
                ->nullable()
                ->constrained('plan_subscriptions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_changes', function (Blueprint $table) {
            $table->dropConstrainedForeignId(
                'target_plan_subscription_id'
            );

            $table->dropColumn('change_mode');
        });

        Schema::table('plan_subscriptions', function (Blueprint $table) {
            $table->dropColumn(
                'cancellation_requested_at'
            );
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropIndex(['tier']);
            $table->dropColumn('tier');
        });
    }
};
