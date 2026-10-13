<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_checkout_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->string('payload_hash', 64);
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key'], 'pos_checkout_tenant_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_checkout_operations');
    }
};

