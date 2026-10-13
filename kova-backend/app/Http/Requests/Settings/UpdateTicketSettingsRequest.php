<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTicketSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
            'plan' => ['prohibited'],
            'allows_invoicing' => ['prohibited'],
            'fiscal_ready' => ['prohibited'],
            'branch_ready' => ['prohibited'],
            'folio_prefix' => [
                'required',
                'string',
                'min:2',
                'max:10',
                'regex:/^[A-Za-z0-9]+$/',
            ],

            'header_text' => [
                'nullable',
                'string',
                'max:255',
            ],

            'footer_text' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'additional_text' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'show_logo' => [
                'required',
                'boolean',
            ],

            'show_business_name' => [
                'required',
                'boolean',
            ],

            'show_branch_name' => [
                'required',
                'boolean',
            ],

            'show_address' => [
                'required',
                'boolean',
            ],

            'show_phone' => [
                'required',
                'boolean',
            ],

            'show_email' => [
                'required',
                'boolean',
            ],

            'show_rfc' => [
                'required',
                'boolean',
            ],

            'show_cashier' => [
                'required',
                'boolean',
            ],

            'show_folio' => [
                'required',
                'boolean',
            ],

            'show_datetime' => [
                'required',
                'boolean',
            ],

            'show_order_type' => [
                'required',
                'boolean',
            ],

            'show_unit_price' => [
                'required',
                'boolean',
            ],

            'show_tax_breakdown' => [
                'required',
                'boolean',
            ],

            'show_payment_method' => [
                'required',
                'boolean',
            ],

            'show_cash_received' => [
                'required',
                'boolean',
            ],

            'show_change' => [
                'required',
                'boolean',
            ],

            'show_invoice_qr' => [
                'required',
                'boolean',
            ],

            'invoice_qr_text' => [
                'nullable',
                'string',
                'max:255',
            ],

            'paper_width_mm' => [
                'required',
                'integer',
                Rule::in([
                    58,
                    80,
                ]),
            ],

            'auto_print' => [
                'required',
                'boolean',
            ],

            'copies' => [
                'required',
                'integer',
                'min:1',
                'max:3',
            ],
        ];
    }
}
