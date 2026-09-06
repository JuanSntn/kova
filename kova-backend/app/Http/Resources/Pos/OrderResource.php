<?php

namespace App\Http\Resources\Pos;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'folio' => $this->folio,
            'order_type' => $this->order_type,
            'status' => $this->status,
            'subtotal' => $this->subtotal,
            'tax_base_total' => $this->tax_base_total,
            'tax_total' => $this->tax_total,
            'discount_total' => $this->discount_total,
            'service_charge_total' => $this->service_charge_total,
            'tip_total' => $this->tip_total,
            'total' => $this->total,
            'payment_method' => $this->payment_method,
            'amount_received' => $this->amount_received,
            'change_amount' => $this->change_amount,
            'paid_at' => $this->paid_at?->toISOString(),
            'items' => $this->whenLoaded('items', fn() => $this->items->map(fn($item): array => [
                'product_id' => $item->product?->public_id,
                'product_name' => $item->product_name_snapshot,
                'unit_price' => $item->unit_price_snapshot,
                'quantity' => $item->quantity,
                'tax_name' => $item->tax_name_snapshot,
                'tax_code' => $item->tax_code_snapshot,
                'tax_factor_type' => $item->tax_factor_type_snapshot,
                'tax_rate' => $item->tax_rate_snapshot,
                'tax_base' => $item->tax_base,
                'tax_amount' => $item->tax_amount,
                'line_total' => $item->line_total,
            ])->values()),
        ];
    }
}
