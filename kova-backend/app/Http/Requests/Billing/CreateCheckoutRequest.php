<?php

namespace App\Http\Requests\Billing;

use App\Enums\PermissionName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(PermissionName::BillingManage->value) === true;
    }

    public function rules(): array
    {
        return [
            'plan' => [
                'required',
                'string',
                Rule::exists('plans', 'code')->where('is_active', true),
            ],
            'interval' => ['sometimes', 'string', Rule::in(['month', 'year'])],
            'offer_id' => ['sometimes', 'uuid'],
            'retained_branch_ids' => ['sometimes', 'array'],
            'retained_branch_ids.*' => ['uuid', 'distinct'],
            'retained_membership_ids' => ['sometimes', 'array'],
            'retained_membership_ids.*' => ['uuid', 'distinct'],
            'price_id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'tax_rate_id' => ['prohibited'],
            'amount' => ['prohibited'],
        ];
    }
}
