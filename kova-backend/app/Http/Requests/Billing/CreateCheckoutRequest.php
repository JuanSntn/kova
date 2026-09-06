<?php

namespace App\Http\Requests\Billing;

use App\Enums\PermissionName;
use App\Enums\PlanCode;
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
                Rule::in([PlanCode::Business->value, PlanCode::Restaurant->value]),
            ],
            'price_id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'tax_rate_id' => ['prohibited'],
            'amount' => ['prohibited'],
        ];
    }
}
