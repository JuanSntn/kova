<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_modifier_groups', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('tenant_id')->constrained();
            $t->string('name', 100);
            $t->string('kind', 20);
            $t->unsignedInteger('version')->default(1);
            $t->boolean('is_active')->default(true);
            $t->json('options');
            $t->timestamps();
        });
        Schema::create('menu_product_modifiers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->foreignId('group_id')->constrained('menu_modifier_groups');
            $t->unsignedInteger('min_selections');
            $t->unsignedInteger('max_selections');
            $t->json('options');
            $t->timestamps();
            $t->unique(['tenant_id', 'product_id', 'group_id']);
        });
        Schema::table('order_items', function (Blueprint $t) {
            $t->json('modifiers_snapshot')->nullable();
            $t->json('inventory_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn(['modifiers_snapshot', 'inventory_snapshot']));
        Schema::dropIfExists('menu_product_modifiers');
        Schema::dropIfExists('menu_modifier_groups');
    }
};
