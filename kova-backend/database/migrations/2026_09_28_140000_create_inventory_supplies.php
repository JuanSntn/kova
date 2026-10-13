<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_supplies', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('category', 100);
            $table->string('base_unit', 2);
            $table->decimal('minimum', 18, 6)->nullable();
            $table->decimal('ideal', 18, 6)->nullable();
            $table->boolean('produced')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['tenant_id', 'name']);
        });
        Schema::create('inventory_presentations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('supply_id')->constrained('inventory_supplies')->cascadeOnDelete();
            $table->string('name', 100);
            $table->decimal('quantity', 18, 6);
            $table->string('unit', 2);
            $table->decimal('base_quantity', 18, 6);
            $table->string('supplier', 150)->nullable();
            $table->string('supplier_code', 100)->nullable();
            $table->decimal('price', 14, 2)->nullable();
            $table->foreignId('tax_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_presentations');
        Schema::dropIfExists('inventory_supplies');
    }
};
