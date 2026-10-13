<?php

namespace App\Http\Requests\Settings;

use App\Enums\RoleName;
use App\Models\Membership;
use App\Services\Memberships\MembershipService;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateStaffUserRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $values = [];
        if ($this->has('name')) $values['name'] = trim((string) $this->input('name'));
        if ($this->has('username')) $values['username'] = MembershipService::normalizeLoginName((string) $this->input('username'));
        $this->merge($values);
    }

    public function rules(): array
    {
        $membership = $this->route('membership');
        $membershipId = $membership instanceof Membership ? $membership->id : null;
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'username' => ['sometimes', 'required', 'string', 'min:2', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('memberships', 'login_name_normalized')->where('tenant_id', app(CurrentTenant::class)->id())->ignore($membershipId)],
            'role' => ['sometimes', 'required', Rule::in([RoleName::Cashier->value, RoleName::Waiter->value, RoleName::Kitchen->value, RoleName::Bar->value])],
        ];
    }

    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated();
        if (isset($data['username'])) $data['username'] = MembershipService::normalizeLoginName($data['username']);
        return $key === null ? $data : data_get($data, $key, $default);
    }
}
