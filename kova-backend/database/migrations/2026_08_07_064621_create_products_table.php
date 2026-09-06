<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            /*
             * Tenant propietario del producto.
             */
            $table->foreignId('tenant_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * Categoría a la que pertenece.
             */
            $table->foreignId('category_id')
                ->constrained('categories')
                ->restrictOnDelete();

            /*
             * Identificador público para API/frontend.
             */
            $table->uuid('public_id')
                ->unique();

            $table->string('name', 160);

            $table->text('description')
                ->nullable();

            /*
             * Precio de venta.
             */
            $table->decimal(
                'price',
                12,
                2
            );

            /*
             * Por ahora guardamos únicamente la ruta.
             * Después podemos definir almacenamiento
             * local/S3/etc.
             */
            $table->string(
                'image_path',
                500
            )->nullable();

            /*
             * Orden visual dentro de la categoría.
             */
            $table->unsignedInteger('sort_order')
                ->default(0);

            $table->boolean('is_active')
                ->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index([
                'tenant_id',
                'category_id',
                'is_active',
            ]);

            $table->index([
                'tenant_id',
                'is_active',
            ]);

            $table->index([
                'category_id',
                'sort_order',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
