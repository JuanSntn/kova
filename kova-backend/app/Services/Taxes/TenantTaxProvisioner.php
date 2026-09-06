<?php

namespace App\Services\Taxes;

use App\Models\Tenant;
use App\Models\TenantTaxSetting;
use App\Models\Tax;

final class TenantTaxProvisioner
{
    /**
     * @return array{default: Tax, taxes: array<int, Tax>}
     */
    public function provisionDefaults(Tenant $tenant): array
    {
        $defaults = [
            [
                'name' => 'IVA 16%',
                'code' => 'iva',
                'factor_type' => 'rate',
                'rate' => '16.0000',
            ],
            [
                'name' => 'IVA 0%',
                'code' => 'iva',
                'factor_type' => 'rate',
                'rate' => '0.0000',
            ],
            [
                'name' => 'Exento',
                'code' => 'iva',
                'factor_type' => 'exempt',
                'rate' => '0.0000',
            ],
        ];

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

            if (! $tax->is_active) {
                $tax->update(['is_active' => true]);
            }

            $taxes[] = $tax;

            if ($taxData['factor_type'] === 'rate' && $taxData['rate'] === '16.0000') {
                $defaultTax = $tax;
            }
        }

        /** @var Tax $defaultTax */
        TenantTaxSetting::query()->updateOrCreate(
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
