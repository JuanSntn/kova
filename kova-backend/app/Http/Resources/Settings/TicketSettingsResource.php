<?php

namespace App\Http\Resources\Settings;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketSettingsResource extends JsonResource
{
    public function toArray(
        Request $request
    ): array {
        return [
            'folio_prefix' =>
            $this->folio_prefix,

            'header_text' =>
            $this->header_text,

            'footer_text' =>
            $this->footer_text,

            'additional_text' =>
            $this->additional_text,

            'visibility' => [
                'logo' =>
                $this->show_logo,

                'business_name' =>
                $this->show_business_name,

                'branch_name' =>
                $this->show_branch_name,

                'address' =>
                $this->show_address,

                'phone' =>
                $this->show_phone,

                'email' =>
                $this->show_email,

                'rfc' =>
                $this->show_rfc,

                'cashier' =>
                $this->show_cashier,

                'folio' =>
                $this->show_folio,

                'datetime' =>
                $this->show_datetime,

                'order_type' =>
                $this->show_order_type,

                'unit_price' =>
                $this->show_unit_price,

                'tax_breakdown' =>
                $this->show_tax_breakdown,

                'payment_method' =>
                $this->show_payment_method,

                'cash_received' =>
                $this->show_cash_received,

                'change' =>
                $this->show_change,
            ],

            'invoice' => [
                'show_qr' =>
                $this->show_invoice_qr,

                'qr_text' =>
                $this->invoice_qr_text,
            ],

            'printing' => [
                'paper_width_mm' =>
                $this->paper_width_mm,

                'auto_print' =>
                $this->auto_print,

                'copies' =>
                $this->copies,
            ],
        ];
    }
}
