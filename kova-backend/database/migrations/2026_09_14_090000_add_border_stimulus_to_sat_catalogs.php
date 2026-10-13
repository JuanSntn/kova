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
                ->unsignedTinyInteger('border_stimulus')
                ->nullable();

            $table->index(
                [
                    'catalog_version_id',
                    'border_stimulus',
                ],
                'sat_postal_codes_border_stimulus_idx',
            );
        });

        Schema::table('sat_product_services', function (Blueprint $table) {
            $table
                ->unsignedTinyInteger('border_stimulus')
                ->nullable();

            $table->index(
                [
                    'catalog_version_id',
                    'border_stimulus',
                ],
                'sat_product_services_border_stimulus_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('sat_product_services', function (Blueprint $table) {
            $table->dropIndex(
                'sat_product_services_border_stimulus_idx',
            );

            $table->dropColumn(
                'border_stimulus',
            );
        });

        Schema::table('sat_postal_codes', function (Blueprint $table) {
            $table->dropIndex(
                'sat_postal_codes_border_stimulus_idx',
            );

            $table->dropColumn(
                'border_stimulus',
            );
        });
    }
};
