<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessProfileRequest extends FormRequest
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
                'min:2',
                'max:160',
            ],

            'phone' => [
                'required',
                'string',
                'max:30',
                'regex:/^[0-9+\-\s().]+$/',
            ],

            'public_email' => [
                'required',
                'email',
                'max:160',
            ],

            'logo' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],

            'remove_logo' => [
                'sometimes',
                'boolean',
            ],
        ];
    }
}
