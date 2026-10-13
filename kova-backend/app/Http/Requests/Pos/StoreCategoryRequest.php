<?php

namespace App\Http\Requests\Pos;

use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // El tenant y el acceso operativo son responsabilidad del backend.
            'id' => ['prohibited'],
            'public_id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'billing_enabled' => ['prohibited'],
            'locked_by_plan' => ['prohibited'],
            'can_edit' => ['prohibited'],
            'can_delete' => ['prohibited'],
            'can_use' => ['prohibited'],

            'name' => [
                'required',
                'string',
                'max:120',
            ],

            /*
            |--------------------------------------------------------------------------
            | Orden
            |--------------------------------------------------------------------------
            |
            | 0 = sin posición específica.
            | Puede repetirse.
            |
            | 1, 2, 3... = posición manual.
            | Debe ser única dentro del tenant.
            |
            */

            'sort_order' => [
                'sometimes',
                'integer',
                'min:0',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Validaciones adicionales
    |--------------------------------------------------------------------------
    */

    public function withValidator(
        Validator $validator
    ): void {
        $validator->after(
            function (
                Validator $validator
            ): void {
                /*
                 * Si no viene sort_order,
                 * dejamos que use el valor
                 * predeterminado.
                 */
                if (! $this->has('sort_order')) {
                    return;
                }

                $sortOrder = (int) $this->input(
                    'sort_order',
                    0
                );

                /*
                 * 0 significa "sin orden manual".
                 *
                 * Puede repetirse.
                 */
                if ($sortOrder === 0) {
                    return;
                }

                /*
                 * Tenant actual resuelto
                 * por middleware.
                 */
                $tenant = app(
                    CurrentTenant::class
                )->get();

                /*
                 * Verificamos si otra categoría
                 * del mismo tenant ya usa
                 * esta posición.
                 */
                $exists = $tenant
                    ->categories()
                    ->where(
                        'sort_order',
                        $sortOrder
                    )
                    ->exists();

                if ($exists) {
                    $validator
                        ->errors()
                        ->add(
                            'sort_order',
                            "La posición {$sortOrder} ya está siendo utilizada por otra categoría."
                        );
                }
            }
        );
    }
}
