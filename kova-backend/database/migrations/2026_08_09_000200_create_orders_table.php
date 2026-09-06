<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('folio', 40);
            $table->string('order_type', 20);
            $table->string('status', 20);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('tax_base_total', 12, 2);
            $table->decimal('tax_total', 12, 2);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('service_charge_total', 12, 2)->default(0);
            $table->decimal('tip_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->string('payment_method', 20);
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'folio']);
            $table->index(['tenant_id', 'status', 'paid_at']);
            $table->index(['tenant_id', 'paid_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('product_name_snapshot', 160);
            $table->decimal('unit_price_snapshot', 12, 2);
            $table->unsignedInteger('quantity');
            $table->string('tax_name_snapshot', 100);
            $table->string('tax_code_snapshot', 30);
            $table->string('tax_factor_type_snapshot', 20);
            $table->decimal('tax_rate_snapshot', 7, 4);
            $table->decimal('tax_base', 12, 2);
            $table->decimal('tax_amount', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
