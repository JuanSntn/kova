<?php

namespace App\Http\Resources\Pos;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,

            'folio' => $this->folio,

            'status' => $this->status,

            'payment_method' =>
            $this->payment_method,

            'total' =>
            (float) $this->total,

            'paid_at' =>
            $this->paid_at
                ?->toIso8601String(),
        ];
    }
}
