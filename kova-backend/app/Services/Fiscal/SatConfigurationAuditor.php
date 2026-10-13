<?php

namespace App\Services\Fiscal;

use App\Models\Tenant;
use App\Models\TenantFiscalProfile;
use DomainException;
use Illuminate\Validation\ValidationException;

final class SatConfigurationAuditor
{
    public function __construct(private SatCatalogService $catalogs) {}

    public function audit(Tenant $tenant, TenantFiscalProfile $profile): array
    {
        try {
            $issues = [];
            if ($profile->rfc) {
                try {
                    $this->catalogs->assertProfile($profile->only(['rfc', 'tax_regime_code', 'postal_code', 'default_product_service_code', 'default_unit_code', 'default_tax_object_code']));
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $field => $messages) {
                        $issues[] = ['scope' => 'profile', 'field' => $field, 'message' => $messages[0]];
                    }
                }
            }
            $products = $tenant->products()->select(['public_id', 'sat_product_service_code', 'sat_unit_code', 'sat_tax_object_code'])->get();
            foreach ($products as $product) {
                try {
                    $this->catalogs->assertProduct($product->only(['sat_product_service_code', 'sat_unit_code', 'sat_tax_object_code']));
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $field => $messages) {
                        $issues[] = ['scope' => 'product', 'resource_id' => $product->public_id, 'field' => $field, 'message' => $messages[0]];
                    }
                }
            }

            return ['valid' => $issues === [], 'issues' => $issues, 'catalog_version' => $this->catalogs->versionName()];
        } catch (DomainException $e) {
            return ['valid' => false, 'issues' => [['scope' => 'catalog', 'field' => null, 'message' => $e->getMessage()]], 'catalog_version' => null];
        }
    }
}
