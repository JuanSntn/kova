<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table
                ->string('postal_code', 5)
                ->nullable()
                ->after('timezone');

            $table
                ->string('state', 120)
                ->nullable()
                ->after('postal_code');

            $table
                ->string('municipality', 180)
                ->nullable()
                ->after('state');

            $table
                ->string('locality', 180)
                ->nullable()
                ->after('municipality');

            $table
                ->string('city', 180)
                ->nullable()
                ->after('locality');

            $table
                ->string('neighborhood', 180)
                ->nullable()
                ->after('city');

            $table
                ->string('street', 180)
                ->nullable()
                ->after('neighborhood');

            $table
                ->string('exterior_number', 30)
                ->nullable()
                ->after('street');

            $table
                ->string('interior_number', 30)
                ->nullable()
                ->after('exterior_number');

            $table
                ->string('country_code', 2)
                ->default('MX')
                ->after('interior_number');

            $table
                ->decimal('latitude', 10, 7)
                ->nullable()
                ->after('country_code');

            $table
                ->decimal('longitude', 10, 7)
                ->nullable()
                ->after('latitude');

            $table->index([
                'tenant_id',
                'postal_code',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropIndex([
                'tenant_id',
                'postal_code',
            ]);

            $table->dropColumn([
                'postal_code',
                'state',
                'municipality',
                'locality',
                'city',
                'neighborhood',
                'street',
                'exterior_number',
                'interior_number',
                'country_code',
                'latitude',
                'longitude',
            ]);
        });
    }
};
