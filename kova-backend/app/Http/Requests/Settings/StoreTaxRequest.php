<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:100',
            ],

            'code' => [
                'required',
                'string',
                'max:30',
            ],

            'factor_type' => [
                'required',
                Rule::in([
                    'rate',
                    'exempt',
                ]),
            ],

            'rate' => [
                'required_if:factor_type,rate',
                'nullable',
                'numeric',
                'min:0',
                'max:999.9999',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ];
    }
}
