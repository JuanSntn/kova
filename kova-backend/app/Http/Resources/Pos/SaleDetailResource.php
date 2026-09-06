<?php

namespace App\Http\Resources\Pos;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,

            'folio' => $this->folio,

            'status' => $this->status,

            'order_type' =>
            $this->order_type,

            'payment_method' =>
            $this->payment_method,

            'subtotal' =>
            (float) $this->subtotal,

            'tax_base_total' =>
            (float) $this->tax_base_total,

            'tax_total' =>
            (float) $this->tax_total,

            'discount_total' =>
            (float) $this->discount_total,

            'service_charge_total' =>
            (float) $this->service_charge_total,

            'tip_total' =>
            (float) $this->tip_total,

            'total' =>
            (float) $this->total,

            'amount_received' =>
            $this->amount_received !== null
                ? (float) $this->amount_received
                : null,

            'change_amount' =>
            $this->change_amount !== null
                ? (float) $this->change_amount
                : null,

            'paid_at' =>
            $this->paid_at
                ?->toIso8601String(),

            'items' =>
            $this->whenLoaded(
                'items',
                fn() =>
                $this->items
                    ->map(
                        fn($item) => [
                            'name' =>
                            $item->product_name_snapshot,

                            'unit_price' =>
                            (float) $item->unit_price_snapshot,

                            'quantity' =>
                            (int) $item->quantity,

                            'tax_name' =>
                            $item->tax_name_snapshot,

                            'tax_rate' =>
                            (float) $item->tax_rate_snapshot,

                            'tax_base' =>
                            (float) $item->tax_base,

                            'tax_amount' =>
                            (float) $item->tax_amount,

                            'line_total' =>
                            (float) $item->line_total,
                        ]
                    )
                    ->values(),
            ),
        ];
    }
}
