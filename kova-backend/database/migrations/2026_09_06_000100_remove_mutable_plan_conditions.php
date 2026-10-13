<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn(['monthly_price', 'max_users', 'max_products', 'max_categories', 'monthly_sales_limit', 'included_branches', 'included_stamps', 'additional_stamp_price', 'additional_branch_price', 'allows_counter_orders', 'allows_table_orders', 'allows_cash_shifts', 'allows_inventory', 'allows_invoicing']));
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $t) {
            $t->decimal('monthly_price', 12, 2)->default(0);
            foreach (['max_users', 'max_products', 'max_categories', 'included_branches', 'included_stamps'] as $field) {
                $t->unsignedInteger($field)->nullable();
            }
            foreach (['monthly_sales_limit', 'additional_stamp_price', 'additional_branch_price'] as $field) {
                $t->decimal($field, 14, 2)->nullable();
            }
            foreach (['allows_counter_orders', 'allows_table_orders', 'allows_cash_shifts', 'allows_inventory', 'allows_invoicing'] as $field) {
                $t->boolean($field)->default(false);
            }
        });
    }
};
