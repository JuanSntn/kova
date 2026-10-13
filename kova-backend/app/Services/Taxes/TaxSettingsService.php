<?php

namespace App\Services\Taxes;

use App\Models\Tax;
use App\Models\Tenant;
use App\Models\TenantTaxSetting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class TaxSettingsService
{
    public function __construct(
        private readonly TaxTreatmentCatalog $catalog,
        private readonly TenantTaxProvisioner $provisioner,
    ) {}

    public function list(Tenant $tenant): Collection
    {
        $this->provisioner->provisionDefaults($tenant);

        return $this->catalog
            ->query($tenant)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
    }

    public function create(Tenant $tenant, array $data): Tax
    {
        throw ValidationException::withMessages([
            'tax' => 'Kova administra los tratamientos fiscales disponibles. No puedes crear tasas manuales.',
        ]);
    }

    public function update(Tenant $tenant, Tax $tax, array $data): Tax
    {
        $this->catalog->assertSupported($tax);

        $isActive = (bool) $data['is_active'];

        if (
            $tax->id === $this->setting($tenant)->default_tax_id &&
            $isActive === false
        ) {
            throw ValidationException::withMessages([
                'is_active' => 'No puedes desactivar el impuesto predeterminado.',
            ]);
        }

        if ($isActive === false && $tax->products()->exists()) {
            throw ValidationException::withMessages([
                'is_active' => 'No puedes desactivar un impuesto que está asignado a productos.',
            ]);
        }

        $tax->update([
            'is_active' => $isActive,
        ]);

        return $tax->refresh();
    }

    public function setting(Tenant $tenant): TenantTaxSetting
    {
        $setting = $tenant
            ->taxSettings()
            ->with('defaultTax')
            ->first();

        if (! $setting) {
            $this->provisioner->provisionDefaults($tenant);

            $setting = $tenant
                ->taxSettings()
                ->with('defaultTax')
                ->firstOrFail();
        }

        if (
            ! $this->catalog->supports($setting->defaultTax)
            || ! $setting->defaultTax->is_active
        ) {
            $default = $this->catalog
                ->query($tenant)
                ->where('factor_type', 'rate')
                ->where('rate', '16.0000')
                ->firstOrFail();

            if (! $default->is_active) {
                $default->update(['is_active' => true]);
            }

            $setting->update([
                'default_tax_id' => $default->id,
                'prices_include_tax' => true,
            ]);

            return $setting->refresh()->load('defaultTax');
        }

        return $setting;
    }

    public function updateConfig(
        Tenant $tenant,
        string $defaultTaxPublicId,
    ): TenantTaxSetting {
        $tax = $this->catalog->findActive($tenant, $defaultTaxPublicId);

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
        return $this->catalog
            ->query($tenant)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }
}
