<?php

namespace App\Http\Requests\Branches;

use App\Enums\PermissionName;
use Illuminate\Foundation\Http\FormRequest;

final class CancelAdditionalBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(
            PermissionName::BillingManage->value,
        ) === true;
    }

    public function rules(): array
    {
        return [
            // El servidor resuelve contrato, complemento y cantidad objetivo.
            'addon_id' => ['prohibited'],
            'offer_id' => ['prohibited'],
            'price_id' => ['prohibited'],
            'quantity' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'retained_branch_ids' => ['sometimes', 'array'],
            'retained_branch_ids.*' => ['uuid', 'distinct'],
        ];
    }
}
