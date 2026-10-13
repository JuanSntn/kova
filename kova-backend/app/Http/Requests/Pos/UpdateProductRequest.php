<?php

namespace App\Http\Requests\Pos;

use App\Services\Fiscal\SatCatalogService;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
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
            // El tenant, la sucursal y las capacidades del plan siempre se
            // resuelven en el servidor. Nunca se aceptan desde el cliente.
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
            'preparation_station' => ['sometimes', 'required', 'string', 'in:kitchen,bar,none'],
            'sat_product_service_code' => ['sometimes', 'nullable', 'digits:8'],
            'sat_unit_code' => ['sometimes', 'nullable', 'string', 'max:30'],
            'sat_tax_object_code' => ['sometimes', 'nullable', 'string', 'max:30'],
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
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isEmpty()) {
                try {
                    app(SatCatalogService::class)->assertProduct($this->only(['sat_product_service_code', 'sat_unit_code', 'sat_tax_object_code']));
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $field => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add($field, $message);
                        }
                    }
                }
            }
        });
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
