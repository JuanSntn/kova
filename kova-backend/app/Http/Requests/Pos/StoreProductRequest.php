<?php

namespace App\Http\Requests\Pos;

use App\Services\Fiscal\SatCatalogService;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
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
            'preparation_station' => ['sometimes', 'required', 'string', 'in:kitchen,bar,none'],
            'sat_product_service_code' => ['nullable', 'digits:8'],
            'sat_unit_code' => ['nullable', 'string', 'max:30'],
            'sat_tax_object_code' => ['nullable', 'string', 'max:30'],
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
