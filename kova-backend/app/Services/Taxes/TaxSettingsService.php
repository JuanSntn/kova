<?php

namespace App\Services\Taxes;

use App\Models\Tenant;
use App\Models\TenantTaxSetting;
use App\Models\Tax;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class TaxSettingsService
{
    public function list(Tenant $tenant): Collection
    {
        return $tenant
            ->taxes()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
    }

    public function create(Tenant $tenant, array $data): Tax
    {
        return $tenant
            ->taxes()
            ->create(
                $this->normalizeTaxData($data)
            );
    }

    public function update(Tenant $tenant, Tax $tax, array $data): Tax
    {
        $attributes = [
            'name' => $tax->name,
            'code' => $tax->code,
            'factor_type' => $tax->factor_type,
            'rate' => $tax->rate,
            'is_active' => $tax->is_active,
        ];

        foreach ($data as $key => $value) {
            $attributes[$key] = $value;
        }

        $attributes = $this->normalizeTaxData($attributes);

        if (
            $tax->id === $this->setting($tenant)->default_tax_id &&
            $attributes['is_active'] === false
        ) {
            throw ValidationException::withMessages([
                'is_active' => 'No puedes desactivar el impuesto predeterminado.',
            ]);
        }

        $tax->update($attributes);

        return $tax->refresh();
    }

    public function setting(Tenant $tenant): TenantTaxSetting
    {
        $setting = $tenant
            ->taxSettings()
            ->with('defaultTax')
            ->first();

        if ($setting) {
            return $setting;
        }

        app(TenantTaxProvisioner::class)->provisionDefaults($tenant);

        return $tenant
            ->taxSettings()
            ->with('defaultTax')
            ->firstOrFail();
    }

    public function updateConfig(
        Tenant $tenant,
        string $defaultTaxPublicId,
    ): TenantTaxSetting {
        $tax = $tenant
            ->taxes()
            ->where('public_id', $defaultTaxPublicId)
            ->where('is_active', true)
            ->firstOrFail();

        $setting = $this->setting($tenant);

        $setting->update([
            'default_tax_id' => $tax->id,
            'prices_include_tax' => true,
        ]);

        return $setting
            ->refresh()
            ->load('defaultTax');
    }

    public function findForTenant(
        Tenant $tenant,
        string $publicId,
    ): Tax {
        return $tenant
            ->taxes()
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function normalizeTaxData(array $data): array
    {
        $factorType = $data['factor_type'];

        return [
            'name' => trim($data['name']),
            'code' => trim($data['code']),
            'factor_type' => $factorType,
            'rate' => $factorType === 'exempt'
                ? 0
                : $data['rate'],
            'is_active' => $data['is_active'] ?? true,
        ];
    }
}
