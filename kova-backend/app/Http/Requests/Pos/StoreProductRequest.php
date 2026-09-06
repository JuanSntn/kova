<?php

namespace App\Http\Requests\Pos;

use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => [
                'required',
                'uuid',
            ],

            'tax_id' => [
                'nullable',
                'uuid',
            ],

            'name' => [
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
                'required',
                'decimal:0,2',
                'min:0',
                'max:9999999999.99',
            ],

            'image' => [
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
                 * Si el frontend no mandó sort_order,
                 * dejamos que el backend use su valor
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
                 * Puede existir en varios productos.
                 */
                if ($sortOrder === 0) {
                    return;
                }

                /*
                 * Tenant actual resuelto por middleware.
                 */
                $tenant = app(
                    CurrentTenant::class
                )->get();

                /*
                 * Verificamos solamente productos
                 * pertenecientes al tenant actual.
                 */
                $exists = $tenant
                    ->products()
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
                            "La posición {$sortOrder} ya está siendo utilizada por otro producto."
                        );
                }
            }
        );
    }
}
