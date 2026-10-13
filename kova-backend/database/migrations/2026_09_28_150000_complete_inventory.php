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
        Schema::create('inventory_categories', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('name', 100);
            $t->string('normalized_name', 100);
            $t->timestamps();
            $t->unique(['tenant_id', 'normalized_name']);
        });
        Schema::table('inventory_supplies', function (Blueprint $t) {
            $t->foreignId('category_id')->nullable()->constrained('inventory_categories')->restrictOnDelete();
        });
        foreach (DB::table('inventory_supplies')->orderBy('id')->cursor() as $s) {
            $name = trim($s->category);
            $normal = mb_strtolower($name);
            $id = DB::table('inventory_categories')->where('tenant_id', $s->tenant_id)->where('normalized_name', $normal)->value('id');
            $id ??= DB::table('inventory_categories')->insertGetId(['public_id' => (string) Str::uuid(), 'tenant_id' => $s->tenant_id, 'name' => $name, 'normalized_name' => $normal, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('inventory_supplies')->where('id', $s->id)->update(['category_id' => $id]);
        }
        Schema::create('inventory_recipes', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('name', 150);
            $t->string('type', 20);
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('output_supply_id')->nullable()->constrained('inventory_supplies')->restrictOnDelete();
            $t->string('yield_quantity', 40);
            $t->string('yield_unit', 2);
            $t->json('components');
            $t->timestamps();
            $t->index(['tenant_id', 'type']);
        });
        Schema::create('inventory_product_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('supply_id')->nullable()->constrained('inventory_supplies')->restrictOnDelete();
            $t->foreignId('recipe_id')->nullable()->constrained('inventory_recipes')->restrictOnDelete();
            $t->string('quantity', 40);
            $t->timestamps();
        });
        Schema::create('inventory_operations', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->uuid('operation_key');
            $t->string('fingerprint', 64);
            $t->string('type', 30);
            $t->foreignId('order_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->text('reason');
            $t->json('snapshot');
            $t->timestamp('created_at');
            $t->unique(['tenant_id', 'operation_key']);
        });
        Schema::create('inventory_balances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('supply_id')->constrained('inventory_supplies')->restrictOnDelete();
            // Canonical decimals stored as strings avoid SQLite REAL arithmetic.
            $t->string('quantity', 40)->default('0.000000');
            $t->string('minimum', 40)->nullable();
            $t->string('ideal', 40)->nullable();
            $t->boolean('initial_recorded')->default(false);
            $t->timestamps();
            $t->unique(['branch_id', 'supply_id']);
        });
        Schema::create('inventory_movements', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('operation_id')->constrained('inventory_operations')->restrictOnDelete();
            $t->foreignId('supply_id')->constrained('inventory_supplies')->restrictOnDelete();
            $t->string('quantity', 40);
            $t->string('balance_after', 40);
            $t->string('unit', 2);
            $t->string('supply_name', 150);
            $t->timestamp('created_at');
            $t->index(['tenant_id', 'branch_id', 'id']);
        });
        Schema::create('inventory_imports', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('file_hash', 64);
            $t->json('payload');
            $t->unsignedInteger('rows_count');
            $t->timestamp('created_at');
            $t->timestamp('committed_at')->nullable();
            $t->unique(['tenant_id', 'file_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_imports');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_balances');
        Schema::dropIfExists('inventory_operations');
        Schema::dropIfExists('inventory_product_links');
        Schema::dropIfExists('inventory_recipes');
        Schema::table('inventory_supplies', fn (Blueprint $t) => $t->dropConstrainedForeignId('category_id'));
        Schema::dropIfExists('inventory_categories');
    }
};
