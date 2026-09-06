<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
            ],

            'code' => [
                'sometimes',
                'required',
                'string',
                'max:30',
            ],

            'factor_type' => [
                'sometimes',
                'required',
                Rule::in([
                    'rate',
                    'exempt',
                ]),
            ],

            'rate' => [
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
