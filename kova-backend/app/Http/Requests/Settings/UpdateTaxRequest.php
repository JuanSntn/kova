<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

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
                'prohibited',
            ],

            'code' => [
                'prohibited',
            ],

            'factor_type' => [
                'prohibited',
            ],

            'rate' => [
                'prohibited',
            ],

            'is_active' => [
                'required',
                'boolean',
            ],
        ];
    }
}
