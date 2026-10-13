<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Early development databases placed this relationship on tickets.
        // A replacement is another immutable CFDI, so it belongs on invoices.
        if (Schema::hasColumn('tickets', 'replaces_invoice_id')) {
            Schema::table('tickets', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('replaces_invoice_id');
            });
        }

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique(['order_id']);
            $table->foreignId('replaces_invoice_id')
                ->nullable()
                ->after('ticket_id')
                ->constrained('invoices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('replaces_invoice_id');
            $table->unique('order_id');
        });

        Schema::table('tickets', function (Blueprint $table): void {
            $table->foreignId('replaces_invoice_id')
                ->nullable()
                ->constrained('invoices')
                ->nullOnDelete();
        });
    }
};
