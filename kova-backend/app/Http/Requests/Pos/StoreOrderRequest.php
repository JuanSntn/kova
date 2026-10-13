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
            'idempotency_key' => ['required', 'uuid'],
            'quote_token' => ['required', 'string', 'max:100000'],
            'branch_id' => ['sometimes', 'nullable', 'uuid'],
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
                'max:999999999.99',
                'decimal:0,2',
            ],

            'items' => [
                'required',
                'array',
                'min:1',
                'max:200',
            ],

            'items.*.product_id' => [
                'required',
                'uuid',
            ],
            'items.*' => ['array:product_id,quantity,modifiers'],
            'items.*.modifiers' => ['sometimes', 'array', 'max:100'],
            'items.*.modifiers.*' => ['required', 'uuid'],

            'items.*.quantity' => [
                'required',
                'integer',
                'min:1',
                'max:99',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        // The header is authoritative; a body field cannot replace it.
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }
}
