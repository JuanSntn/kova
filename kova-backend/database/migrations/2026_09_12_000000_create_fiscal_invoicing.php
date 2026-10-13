<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_fiscal_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('rfc', 13)->nullable();
            $table->string('legal_name', 254)->nullable();
            $table->string('tax_regime_code', 3)->nullable();
            $table->string('postal_code', 5)->nullable();
            $table->string('fiscal_email')->nullable();
            $table->string('default_series', 25)->default('A');
            $table->string('default_product_service_code', 8)->nullable();
            $table->string('default_unit_code', 3)->nullable();
            $table->string('default_unit_name', 80)->nullable();
            $table->string('default_tax_object_code', 2)->nullable();
            $table->string('status', 30)->default('incomplete');
            $table->timestamp('validated_at')->nullable();
            $table->string('blocked_reason_code', 60)->nullable();
            $table->timestamps();
        });

        Schema::create('fiscal_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_fiscal_profile_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('serial_number', 40);
            $table->string('rfc', 13);
            $table->string('legal_name', 254)->nullable();
            $table->timestamp('valid_from');
            $table->timestamp('valid_until');
            $table->string('sha256_fingerprint', 64);
            $table->string('certificate_path', 500);
            $table->string('private_key_path', 500);
            $table->text('encrypted_password');
            $table->unsignedSmallInteger('encryption_key_version');
            $table->string('status', 30)->default('pending');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'sha256_fingerprint']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('tenant_pac_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_fiscal_profile_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('environment', 20);
            $table->string('remote_identifier')->nullable();
            $table->string('status', 30)->default('pending');
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'provider', 'environment']);
        });

        Schema::create('fiscal_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('series', 25);
            $table->unsignedBigInteger('next_folio')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'series']);
        });

        Schema::create('fiscal_receiver_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('rfc', 13);
            $table->string('legal_name', 254);
            $table->string('tax_regime_code', 3);
            $table->string('postal_code', 5);
            $table->string('email')->nullable();
            $table->string('foreign_tax_id', 80)->nullable();
            $table->string('country_code', 3)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'rfc']);
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('token_hash', 64)->unique();
            $table->text('encrypted_token');
            $table->boolean('fiscal_eligible')->default(false);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('ticket_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('fiscal_receiver_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('provider', 30)->default('finkok');
            $table->string('series', 25);
            $table->unsignedBigInteger('folio');
            $table->json('issuer_snapshot');
            $table->json('receiver_snapshot');
            $table->decimal('subtotal', 14, 2);
            $table->decimal('tax_total', 14, 2);
            $table->decimal('total', 14, 2);
            $table->string('currency', 3)->default('MXN');
            $table->uuid('fiscal_uuid')->nullable()->unique();
            $table->string('status', 30)->default('queued');
            $table->string('idempotency_key_hash', 64);
            $table->string('unsigned_xml_path', 500)->nullable();
            $table->string('stamped_xml_path', 500)->nullable();
            $table->string('pdf_path', 500)->nullable();
            $table->string('cancellation_receipt_path', 500)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'series', 'folio']);
            $table->unique(['tenant_id', 'idempotency_key_hash']);
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'order_id']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 1000);
            $table->decimal('quantity', 18, 6);
            $table->decimal('unit_value', 18, 6);
            $table->decimal('amount', 18, 6);
            $table->string('product_service_code', 8);
            $table->string('unit_code', 3);
            $table->string('unit_name', 80)->nullable();
            $table->string('tax_object_code', 2);
            $table->string('tax_code', 6)->nullable();
            $table->string('tax_factor_type', 20)->nullable();
            $table->decimal('tax_rate', 10, 6)->nullable();
            $table->decimal('tax_base', 18, 6)->nullable();
            $table->decimal('tax_amount', 18, 6)->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('operation', 30);
            $table->unsignedInteger('attempt_number');
            $table->string('status', 30);
            $table->string('remote_code', 100)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('safe_error', 500)->nullable();
            $table->timestamps();
            $table->unique(['invoice_id', 'operation', 'attempt_number']);
        });

        Schema::create('fiscal_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('operation_key')->unique();
            $table->string('kind', 30);
            $table->json('payload');
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
        });

        Schema::create('subscription_overage_policies', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('feature_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_addon_id')->constrained()->restrictOnDelete();
            $table->foreignId('addon_price_id')->constrained()->restrictOnDelete();
            $table->boolean('enabled')->default(false);
            $table->unsignedBigInteger('unit_amount');
            $table->unsignedInteger('tax_rate_basis_points')->default(1600);
            $table->unsignedBigInteger('estimated_unit_total');
            $table->string('currency', 3)->default('mxn');
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->foreignId('consented_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('consented_at')->nullable();
            $table->timestamps();
            $table->unique(['plan_subscription_id', 'feature_id', 'addon_price_id'], 'overage_policy_contract_feature_price_unique');
            $table->index(['tenant_id', 'enabled', 'effective_until']);
        });

        Schema::create('usage_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('usage_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_overage_policy_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence_number');
            $table->string('usage_type', 20);
            $table->foreignId('addon_price_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_amount')->nullable();
            $table->unsignedInteger('tax_rate_basis_points')->nullable();
            $table->unsignedBigInteger('estimated_unit_total')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('status', 30)->default('reserved');
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->unique(['usage_period_id', 'sequence_number']);
            $table->index(['usage_period_id', 'status', 'expires_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('sat_product_service_code', 8)->nullable();
            $table->string('sat_unit_code', 3)->nullable();
            $table->string('sat_unit_name', 80)->nullable();
            $table->string('sat_tax_object_code', 2)->nullable();
        });

        Schema::table('usage_events', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('usage_reservation_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('sequence_number')->nullable();
            $table->string('usage_type', 20)->nullable();
            $table->boolean('is_overage')->default(false);
            $table->foreignId('addon_price_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_amount')->nullable();
            $table->unsignedInteger('tax_rate_basis_points')->nullable();
            $table->unsignedBigInteger('estimated_unit_total')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('metering_status', 30)->default('not_billable');
            $table->timestamp('metered_at')->nullable();
            $table->unique(['usage_period_id', 'sequence_number'], 'usage_event_period_sequence_unique');
            $table->unique('invoice_id', 'usage_event_invoice_unique');
        });
    }

    public function down(): void
    {
        Schema::table('usage_events', function (Blueprint $table) {
            $table->dropUnique('usage_event_period_sequence_unique');
            $table->dropUnique('usage_event_invoice_unique');
            $table->dropConstrainedForeignId('usage_reservation_id');
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropConstrainedForeignId('addon_price_id');
            $table->dropColumn(['sequence_number', 'usage_type', 'is_overage', 'unit_amount', 'tax_rate_basis_points', 'estimated_unit_total', 'currency', 'metering_status', 'metered_at']);
        });
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['sat_product_service_code', 'sat_unit_code', 'sat_unit_name', 'sat_tax_object_code']));
        foreach (['usage_reservations', 'subscription_overage_policies', 'fiscal_outbox', 'invoice_attempts', 'invoice_items', 'invoices', 'tickets', 'fiscal_receiver_profiles', 'fiscal_series', 'tenant_pac_registrations', 'fiscal_certificates', 'tenant_fiscal_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
