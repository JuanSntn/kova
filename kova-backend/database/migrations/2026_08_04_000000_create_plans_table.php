<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 100);
            $table->decimal('monthly_price', 12, 2);
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_products')->nullable();
            $table->decimal('monthly_sales_limit', 14, 2)->nullable();
            $table->unsignedInteger('included_branches')->default(1);
            $table->unsignedInteger('included_stamps')->default(0);
            $table->decimal('additional_stamp_price', 8, 2)->nullable();
            $table->decimal('additional_branch_price', 12, 2)->nullable();
            $table->boolean('allows_counter_orders')->default(true);
            $table->boolean('allows_table_orders')->default(false);
            $table->boolean('allows_cash_shifts')->default(false);
            $table->boolean('allows_inventory')->default(false);
            $table->boolean('allows_invoicing')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
