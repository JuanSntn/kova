<?php

namespace App\Http\Requests\Pos;

use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
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
                 * Si no se está actualizando
                 * sort_order, no hacemos nada.
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
                 * Ruta:
                 *
                 * PUT /api/pos/categories/{category}
                 *
                 * {category} contiene el public_id.
                 */
                $categoryPublicId = $this->route(
                    'category'
                );

                /*
                 * Buscamos si otra categoría
                 * del mismo tenant ya utiliza
                 * esta posición.
                 *
                 * Excluimos la categoría actual.
                 */
                $exists = $tenant
                    ->categories()
                    ->where(
                        'sort_order',
                        $sortOrder
                    )
                    ->where(
                        'public_id',
                        '!=',
                        $categoryPublicId
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
