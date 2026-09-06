<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();

            /*
             * Tenant propietario de la categoría.
             */
            $table->foreignId('tenant_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * ID público que usaremos en la API.
             * No exponemos el ID incremental.
             */
            $table->uuid('public_id')
                ->unique();

            $table->string('name', 120);

            /*
             * Permite ordenar categorías en el POS.
             *
             * Ejemplo:
             * 1 Alimentos
             * 2 Bebidas
             * 3 Postres
             */
            $table->unsignedInteger('sort_order')
                ->default(0);

            $table->boolean('is_active')
                ->default(true);

            $table->timestamps();
            $table->softDeletes();

            /*
             * Un tenant no puede tener dos categorías
             * con exactamente el mismo nombre.
             */
            $table->unique([
                'tenant_id',
                'name',
            ]);

            $table->index([
                'tenant_id',
                'is_active',
            ]);

            $table->index([
                'tenant_id',
                'sort_order',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
