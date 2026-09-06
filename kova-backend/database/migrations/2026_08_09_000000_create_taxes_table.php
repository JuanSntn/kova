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
        Schema::create('taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('name', 100);
            $table->string('code', 30);
            $table->string('factor_type', 20);
            $table->decimal('rate', 7, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code', 'factor_type', 'rate']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('tenant_tax_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('default_tax_id')->constrained('taxes')->restrictOnDelete();
            $table->boolean('prices_include_tax')->default(true);
            $table->timestamps();
        });

        $now = now();

        foreach (DB::table('tenants')->select(['id'])->get() as $tenant) {
            $taxes = [
                ['name' => 'IVA 16%', 'code' => 'iva', 'factor_type' => 'rate', 'rate' => '16.0000'],
                ['name' => 'IVA 0%', 'code' => 'iva', 'factor_type' => 'rate', 'rate' => '0.0000'],
                ['name' => 'Exento', 'code' => 'iva', 'factor_type' => 'exempt', 'rate' => '0.0000'],
            ];

            $defaultTaxId = null;

            foreach ($taxes as $tax) {
                $existing = DB::table('taxes')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $tax['code'])
                    ->where('factor_type', $tax['factor_type'])
                    ->where('rate', $tax['rate'])
                    ->first();

                if ($existing) {
                    $taxId = $existing->id;
                } else {
                    $taxId = DB::table('taxes')->insertGetId([
                        'tenant_id' => $tenant->id,
                        'public_id' => (string) Str::uuid(),
                        ...$tax,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                if ($tax['factor_type'] === 'rate' && $tax['rate'] === '16.0000') {
                    $defaultTaxId = $taxId;
                }
            }

            if ($defaultTaxId !== null) {
                DB::table('tenant_tax_settings')->updateOrInsert(
                    ['tenant_id' => $tenant->id],
                    [
                        'default_tax_id' => $defaultTaxId,
                        'prices_include_tax' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_tax_settings');
        Schema::dropIfExists('taxes');
    }
};
