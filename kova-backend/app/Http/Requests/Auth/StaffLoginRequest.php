<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class StaffLoginRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'min:2', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }
}
