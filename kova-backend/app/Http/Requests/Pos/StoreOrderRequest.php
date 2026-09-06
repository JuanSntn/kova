<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_type' => [
                'required',
                Rule::in([
                    'counter',
                ]),
            ],

            'payment_method' => [
                'required',
                Rule::in([
                    'cash',
                    'card',
                    'transfer',
                ]),
            ],

            'amount_received' => [
                'nullable',
                'required_if:payment_method,cash',
                'prohibited_unless:payment_method,cash',
                'numeric',
                'min:0',
            ],

            'items' => [
                'required',
                'array',
                'min:1',
            ],

            'items.*.product_id' => [
                'required',
                'uuid',
            ],

            'items.*.quantity' => [
                'required',
                'integer',
                'min:1',
                'max:99',
            ],
        ];
    }
}
