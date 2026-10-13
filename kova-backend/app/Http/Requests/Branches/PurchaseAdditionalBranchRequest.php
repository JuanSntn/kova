<?php

namespace App\Http\Requests\Branches;

use App\Enums\PermissionName;
use Illuminate\Foundation\Http\FormRequest;

final class PurchaseAdditionalBranchRequest extends FormRequest
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
            /*
             * La oferta y su importe se resuelven exclusivamente en el
             * servidor desde el contrato activo. Nunca aceptamos precio,
             * tenant o cantidad desde el navegador.
             */
            'tenant_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'offer_id' => ['prohibited'],
            'price_id' => ['prohibited'],
            'quantity' => ['prohibited'],
            'amount' => ['prohibited'],
            'currency' => ['prohibited'],
        ];
    }
}
