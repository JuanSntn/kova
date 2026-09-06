<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('tax_id')
                ->nullable()
                ->after('category_id')
                ->constrained('taxes')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'tax_id']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['tax_id']);
            $table->dropIndex(['tenant_id', 'tax_id']);
            $table->dropColumn('tax_id');
        });
    }
};
