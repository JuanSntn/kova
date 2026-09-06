<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'tenant_business_profiles',
            function (Blueprint $table) {
                $table->id();

                $table
                    ->foreignId('tenant_id')
                    ->unique()
                    ->constrained()
                    ->cascadeOnDelete();

                $table
                    ->string('phone', 30)
                    ->nullable();

                $table
                    ->string('public_email', 160)
                    ->nullable();

                $table
                    ->string('logo_path', 255)
                    ->nullable();

                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'tenant_business_profiles'
        );
    }
};
