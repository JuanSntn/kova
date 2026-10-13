<?php

namespace App\Http\Requests\Branches;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:20',
            ],

            'name' => [
                'sometimes',
                'required',
                'string',
                'max:120',
            ],

            'postal_code' => [
                'sometimes',
                'required',
                'string',
                'regex:/^\d{5}$/',
            ],

            'city' => [
                'sometimes',
                'required',
                'string',
                'max:180',
            ],

            'neighborhood' => [
                'sometimes',
                'required',
                'string',
                'max:180',
            ],

            'street' => [
                'sometimes',
                'required',
                'string',
                'max:180',
            ],

            'exterior_number' => [
                'sometimes',
                'required',
                'string',
                'max:30',
            ],

            'interior_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:30',
            ],

            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:30',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],

            'billing_enabled' => [
                'prohibited',
            ],

            'tenant_id' => [
                'prohibited',
            ],

            'public_id' => [
                'prohibited',
            ],

            'timezone' => [
                'prohibited',
            ],

            'latitude' => [
                'prohibited',
            ],

            'longitude' => [
                'prohibited',
            ],

            'state' => [
                'prohibited',
            ],

            'municipality' => [
                'prohibited',
            ],

            'locality' => [
                'prohibited',
            ],

            'country_code' => [
                'prohibited',
            ],

            'address' => [
                'prohibited',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'El código de la sucursal no puede estar vacío.',

            'code.max' => 'El código de la sucursal no puede superar 20 caracteres.',

            'name.required' => 'El nombre de la sucursal no puede estar vacío.',

            'name.max' => 'El nombre de la sucursal no puede superar 120 caracteres.',

            'postal_code.required' => 'El código postal no puede estar vacío.',

            'postal_code.regex' => 'El código postal debe contener exactamente 5 dígitos.',

            'city.required' => 'La ciudad no puede estar vacía.',

            'neighborhood.required' => 'La colonia no puede estar vacía.',

            'street.required' => 'La calle no puede estar vacía.',

            'exterior_number.required' => 'El número exterior no puede estar vacío.',

            'tenant_id.prohibited' => 'El negocio no puede modificarse desde esta solicitud.',

            'public_id.prohibited' => 'El identificador de la sucursal no puede modificarse.',

            'timezone.prohibited' => 'La zona horaria es determinada automáticamente por Kova.',

            'latitude.prohibited' => 'La latitud es determinada automáticamente por Kova.',

            'longitude.prohibited' => 'La longitud es determinada automáticamente por Kova.',

            'state.prohibited' => 'El estado es determinado automáticamente por Kova.',

            'municipality.prohibited' => 'El municipio es determinado automáticamente por Kova.',

            'locality.prohibited' => 'La localidad es determinada automáticamente por Kova.',

            'country_code.prohibited' => 'El país es determinado automáticamente por Kova.',

            'address.prohibited' => 'La dirección estructurada debe enviarse mediante sus campos individuales.',

            'billing_enabled.prohibited' => 'La disponibilidad comercial de la sucursal es determinada por la suscripción.',
        ];
    }
}
