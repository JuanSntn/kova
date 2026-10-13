<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSupplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inventory.manage');
    }

    public function rules(): array
    {
        $quantity = ['nullable', 'numeric', 'min:0', 'max:999999999', 'decimal:0,6'];

        return [
            'id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'uuid'],
            'version' => [$this->isMethod('PUT') ? 'required' : 'prohibited', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:150'],
            'category_id' => ['nullable', 'uuid'],
            'category' => ['required_without:category_id', 'string', 'max:100'],
            'unit' => ['required', Rule::in(['pz', 'g', 'kg', 'ml', 'L'])],
            'minimum' => $quantity,
            'ideal' => $quantity,
            'produced' => ['required', 'boolean'],
            'presentations' => ['present', 'array', 'max:50'],
            'presentations.*' => ['array:id,name,quantity,unit,supplier,supplierCode,price,tax'],
            'presentations.*.id' => ['required', 'uuid', 'distinct'],
            'presentations.*.name' => ['required', 'string', 'max:100'],
            'presentations.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,6'],
            'presentations.*.unit' => ['required', Rule::in(['pz', 'g', 'kg', 'ml', 'L'])],
            'presentations.*.supplier' => ['nullable', 'string', 'max:150'],
            'presentations.*.supplierCode' => ['nullable', 'string', 'max:100'],
            'presentations.*.price' => ['nullable', 'numeric', 'min:0', 'max:999999999', 'decimal:0,2'],
            'presentations.*.tax' => ['nullable', 'uuid'],
            'tenant_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'stock' => ['prohibited'],
            'allows_inventory' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            '*.required' => 'Completa este campo.',
            'required' => 'Completa este campo.',
            'numeric' => 'Ingresa una cantidad válida.',
            'decimal' => 'Revisa la cantidad de decimales.',
            'min' => 'El valor debe ser mayor o igual a :min.',
            'max' => 'El valor supera el máximo permitido (:max).',
            'gt' => 'La cantidad debe ser mayor que cero.',
            'uuid' => 'El identificador no es válido.',
            'prohibited' => 'Este campo no está permitido.',
        ];
    }
}
