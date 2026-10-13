<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sat_catalog_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version', 40)->unique();
            $table->string('status', 20);
            $table->unsignedTinyInteger('active_slot')->nullable()->unique();
            $table->timestamp('published_at')->nullable();
            $table->string('asset_url', 500)->nullable();
            $table->string('asset_sha256', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->json('counts')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamps();
        });

        $this->codedTable('sat_tax_regimes', function (Blueprint $table) {
            $table->boolean('applies_to_physical');
            $table->boolean('applies_to_legal');
        });
        $this->codedTable('sat_postal_codes', function (Blueprint $table) {
            $table->string('state', 120)->nullable();
            $table->string('municipality', 180)->nullable();
            $table->string('locality', 180)->nullable();
        });
        $this->codedTable('sat_product_services', function (Blueprint $table) {
            $table->boolean('vat_transfer')->nullable();
            $table->boolean('ieps_transfer')->nullable();
        });
        $this->codedTable('sat_units', function (Blueprint $table) {
            $table->text('details')->nullable();
            $table->string('symbol', 30)->nullable();
        });
        $this->codedTable('sat_tax_objects');
        $this->codedTable('sat_cfdi_uses', function (Blueprint $table) {
            $table->boolean('applies_to_physical');
            $table->boolean('applies_to_legal');
            $table->json('receiver_regime_codes');
        });
        $this->codedTable('sat_payment_forms', function (Blueprint $table) {
            $table->boolean('banked');
            $table->boolean('requires_operation_number');
        });
        $this->codedTable('sat_payment_methods');
        $this->codedTable('sat_taxes', function (Blueprint $table) {
            $table->boolean('allows_withholding');
            $table->boolean('allows_transfer');
            $table->string('scope', 30)->nullable();
        });
        $this->codedTable('sat_factor_types');

        Schema::create('sat_rate_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_version_id')->constrained('sat_catalog_versions')->cascadeOnDelete();
            $table->string('rule_type', 20);
            $table->decimal('minimum', 18, 6)->nullable();
            $table->decimal('value', 18, 6);
            $table->string('tax', 100);
            $table->string('factor', 30);
            $table->boolean('allows_transfer');
            $table->boolean('allows_withholding');
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->unique(['catalog_version_id', 'rule_type', 'minimum', 'value', 'tax', 'factor', 'allows_transfer', 'allows_withholding'], 'sat_rate_rules_identity_unique');
        });
    }

    private function codedTable(string $name, ?callable $extra = null): void
    {
        Schema::create($name, function (Blueprint $table) use ($extra) {
            $table->id();
            $table->foreignId('catalog_version_id')->constrained('sat_catalog_versions')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('description', 1000)->default('');
            $table->string('normalized_description', 1000)->default('');
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $extra?->__invoke($table);
            $table->unique(['catalog_version_id', 'code']);
            $table->index(['catalog_version_id', 'valid_from', 'valid_until']);
        });
    }

    public function down(): void
    {
        foreach (['sat_rate_rules', 'sat_factor_types', 'sat_taxes', 'sat_payment_methods', 'sat_payment_forms', 'sat_cfdi_uses', 'sat_tax_objects', 'sat_units', 'sat_product_services', 'sat_postal_codes', 'sat_tax_regimes'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::dropIfExists('sat_catalog_versions');
    }
};
