<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TenantTicketSetting extends Model
{
    protected $fillable = [
        'tenant_id',

        'folio_prefix',

        'display_name',
        'header_text',
        'footer_text',
        'additional_text',

        'show_logo',
        'show_business_name',
        'show_branch_name',
        'show_address',
        'show_phone',
        'show_email',
        'show_rfc',
        'show_cashier',
        'show_folio',
        'show_datetime',
        'show_order_type',

        'show_unit_price',
        'show_tax_breakdown',
        'show_payment_method',
        'show_cash_received',
        'show_change',

        'show_invoice_qr',
        'invoice_qr_text',

        'paper_width_mm',
        'auto_print',
        'copies',
    ];


    protected function casts(): array
    {
        return [
            'show_logo' => 'boolean',
            'show_business_name' => 'boolean',
            'show_branch_name' => 'boolean',
            'show_address' => 'boolean',
            'show_phone' => 'boolean',
            'show_email' => 'boolean',
            'show_rfc' => 'boolean',
            'show_cashier' => 'boolean',
            'show_folio' => 'boolean',
            'show_datetime' => 'boolean',
            'show_order_type' => 'boolean',

            'show_unit_price' => 'boolean',
            'show_tax_breakdown' => 'boolean',
            'show_payment_method' => 'boolean',
            'show_cash_received' => 'boolean',
            'show_change' => 'boolean',

            'show_invoice_qr' => 'boolean',

            'paper_width_mm' => 'integer',
            'auto_print' => 'boolean',
            'copies' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(
            Tenant::class
        );
    }
}
