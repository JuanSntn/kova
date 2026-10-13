<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sat_postal_codes', function (Blueprint $table) {
            $table
                ->string('timezone_description', 120)
                ->nullable()
                ->after('locality');

            $table
                ->string('summer_start_month', 20)
                ->nullable()
                ->after('timezone_description');

            $table
                ->string('summer_start_day', 20)
                ->nullable()
                ->after('summer_start_month');

            $table
                ->string('summer_start_hour', 20)
                ->nullable()
                ->after('summer_start_day');

            $table
                ->string('summer_difference', 20)
                ->nullable()
                ->after('summer_start_hour');

            $table
                ->string('winter_start_month', 20)
                ->nullable()
                ->after('summer_difference');

            $table
                ->string('winter_start_day', 20)
                ->nullable()
                ->after('winter_start_month');

            $table
                ->string('winter_start_hour', 20)
                ->nullable()
                ->after('winter_start_day');

            $table
                ->string('winter_difference', 20)
                ->nullable()
                ->after('winter_start_hour');
        });
    }

    public function down(): void
    {
        Schema::table('sat_postal_codes', function (Blueprint $table) {
            $table->dropColumn([
                'timezone_description',
                'summer_start_month',
                'summer_start_day',
                'summer_start_hour',
                'summer_difference',
                'winter_start_month',
                'winter_start_day',
                'winter_start_hour',
                'winter_difference',
            ]);
        });
    }
};
