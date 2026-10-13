<?php

namespace App\Services\Taxes;

use App\Models\Tax;
use App\Models\Tenant;
use App\Models\TenantTaxSetting;

final class TenantTaxProvisioner
{
    public function __construct(
        private readonly TaxTreatmentCatalog $catalog,
    ) {}

    /**
     * @return array{default: Tax, taxes: array<int, Tax>}
     */
    public function provisionDefaults(Tenant $tenant): array
    {
        $defaults = $this->catalog->definitions();

        $taxes = [];
        $defaultTax = null;

        foreach ($defaults as $taxData) {
            $tax = $tenant
                ->taxes()
                ->firstOrCreate(
                    [
                        'code' => $taxData['code'],
                        'factor_type' => $taxData['factor_type'],
                        'rate' => $taxData['rate'],
                    ],
                    [
                        'name' => $taxData['name'],
                        'is_active' => true,
                    ],
                );

            $taxes[] = $tax;

            if ($taxData['factor_type'] === 'rate' && $taxData['rate'] === '16.0000') {
                $defaultTax = $tax;
            }
        }

        /** @var Tax $defaultTax */
        TenantTaxSetting::query()->firstOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'default_tax_id' => $defaultTax->id,
                'prices_include_tax' => true,
            ],
        );

        return [
            'default' => $defaultTax,
            'taxes' => $taxes,
        ];
    }
}
