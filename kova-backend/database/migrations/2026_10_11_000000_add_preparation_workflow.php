<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('preparation_station', 20)->default('kitchen')->after('is_active');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->after('id');
            $table->string('preparation_station_snapshot', 20)->nullable()->after('inventory_snapshot');
            $table->string('preparation_status', 20)->default('ready')->after('preparation_station_snapshot');
            $table->timestamp('preparation_started_at')->nullable()->after('preparation_status');
            $table->timestamp('preparation_ready_at')->nullable()->after('preparation_started_at');
        });

        DB::table('order_items')->select('id')->orderBy('id')->chunkById(500, function ($items): void {
            foreach ($items as $item) {
                DB::table('order_items')->where('id', $item->id)->update([
                    'public_id' => (string) Str::uuid(),
                ]);
            }
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->unique('public_id');
            $table->index(['preparation_status', 'preparation_station_snapshot'], 'order_items_preparation_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex('order_items_preparation_index');
            $table->dropUnique(['public_id']);
            $table->dropColumn([
                'public_id',
                'preparation_station_snapshot',
                'preparation_status',
                'preparation_started_at',
                'preparation_ready_at',
            ]);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('preparation_station');
        });
    }
};
