<?php

namespace App\Http\Requests\Settings;

use App\Enums\RoleName;
use App\Services\Memberships\MembershipService;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class StoreStaffUserRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'username' => MembershipService::normalizeLoginName((string) $this->input('username')),
        ]);
    }

    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'min:2', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('memberships', 'login_name_normalized')->where('tenant_id', $tenantId)],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'role' => ['required', Rule::in([RoleName::Cashier->value, RoleName::Waiter->value, RoleName::Kitchen->value, RoleName::Bar->value])],
            'branch_ids' => ['required', 'array', 'min:1'],
            'branch_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.required' => 'La contraseña es obligatoria.',
            'password.confirmed' => 'La confirmación de contraseña no coincide.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.mixed' => 'La contraseña debe incluir al menos una letra mayúscula y una minúscula.',
            'password.numbers' => 'La contraseña debe incluir al menos un número.',
        ];
    }

    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated();
        $data['username'] = MembershipService::normalizeLoginName($data['username']);
        return $key === null ? $data : data_get($data, $key, $default);
    }
}
