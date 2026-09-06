<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_ticket_settings', function (Blueprint $table) {
            $table->id();

            $table
                ->foreignId('tenant_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Folio
            |--------------------------------------------------------------------------
            */

            $table
                ->string('folio_prefix', 10)
                ->default('POS');

            /*
            |--------------------------------------------------------------------------
            | Personalización
            |--------------------------------------------------------------------------
            */

            $table
                ->string('display_name', 160)
                ->nullable();

            $table
                ->string('header_text', 255)
                ->nullable();

            $table
                ->text('footer_text')
                ->nullable();

            $table
                ->text('additional_text')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Visibilidad
            |--------------------------------------------------------------------------
            */

            $table
                ->boolean('show_logo')
                ->default(true);

            $table
                ->boolean('show_business_name')
                ->default(true);

            $table
                ->boolean('show_branch_name')
                ->default(true);

            $table
                ->boolean('show_address')
                ->default(true);

            $table
                ->boolean('show_phone')
                ->default(true);

            $table
                ->boolean('show_email')
                ->default(false);

            $table
                ->boolean('show_rfc')
                ->default(false);

            $table
                ->boolean('show_cashier')
                ->default(true);

            $table
                ->boolean('show_folio')
                ->default(true);

            $table
                ->boolean('show_datetime')
                ->default(true);

            $table
                ->boolean('show_order_type')
                ->default(false);

            /*
            |--------------------------------------------------------------------------
            | Venta
            |--------------------------------------------------------------------------
            */

            $table
                ->boolean('show_unit_price')
                ->default(true);

            $table
                ->boolean('show_tax_breakdown')
                ->default(true);

            $table
                ->boolean('show_payment_method')
                ->default(true);

            $table
                ->boolean('show_cash_received')
                ->default(true);

            $table
                ->boolean('show_change')
                ->default(true);

            /*
            |--------------------------------------------------------------------------
            | Facturación
            |--------------------------------------------------------------------------
            */

            $table
                ->boolean('show_invoice_qr')
                ->default(false);

            $table
                ->string('invoice_qr_text', 255)
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Impresión
            |--------------------------------------------------------------------------
            */

            $table
                ->unsignedTinyInteger('paper_width_mm')
                ->default(80);

            $table
                ->boolean('auto_print')
                ->default(false);

            $table
                ->unsignedTinyInteger('copies')
                ->default(1);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'tenant_ticket_settings'
        );
    }
};
