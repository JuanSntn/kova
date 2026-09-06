<?php

namespace App\Http\Requests\Pos;

use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => [
                'sometimes',
                'required',
                'uuid',
            ],

            'tax_id' => [
                'sometimes',
                'nullable',
                'uuid',
            ],

            'name' => [
                'sometimes',
                'required',
                'string',
                'max:160',
            ],

            'description' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'price' => [
                'sometimes',
                'required',
                'decimal:0,2',
                'min:0',
                'max:9999999999.99',
            ],

            'image' => [
                'sometimes',
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
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
                 * sort_order, no validamos nada.
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

                $tenant = app(
                    CurrentTenant::class
                )->get();

                /*
                 * La ruta normalmente es:
                 *
                 * PUT /api/pos/products/{product}
                 *
                 * y {product} contiene el public_id.
                 */
                $productPublicId = $this->route(
                    'product'
                );

                /*
                 * Buscamos si otro producto del mismo
                 * tenant ya usa esta posición.
                 *
                 * Excluimos el producto que estamos
                 * editando.
                 */
                $exists = $tenant
                    ->products()
                    ->where(
                        'sort_order',
                        $sortOrder
                    )
                    ->where(
                        'public_id',
                        '!=',
                        $productPublicId
                    )
                    ->exists();

                if ($exists) {
                    $validator
                        ->errors()
                        ->add(
                            'sort_order',
                            "La posición {$sortOrder} ya está siendo utilizada por otro producto."
                        );
                }
            }
        );
    }
}
