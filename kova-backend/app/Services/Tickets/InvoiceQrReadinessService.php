<?php

namespace App\Services\Tickets;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\TenantFiscalProfile;
use App\Services\Fiscal\SatCatalogService;
use App\Services\Fiscal\SatPostalTimezoneResolver;
use App\Services\Subscriptions\EntitlementService;
use DomainException;
use Illuminate\Validation\ValidationException;

final class InvoiceQrReadinessService
{
    public function __construct(
        private EntitlementService $entitlements,
        private SatCatalogService $catalogs,
        private SatPostalTimezoneResolver $timezones,
    ) {}

    public function for(Tenant $tenant, bool $requested = false): array
    {
        $allowed = $this->entitlements->allows($tenant, 'allows_invoicing');
        $fiscalReady = false;
        $code = 'plan_unavailable';
        $message = 'Tu plan actual no incluye autofacturación.';
        $missing = [];
        $branchesReady = false;

        if ($allowed) {
            $profile = TenantFiscalProfile::query()->where('tenant_id', $tenant->id)->first();
            $code = 'fiscal_incomplete';
            $message = 'Completa y valida tus datos fiscales para activar el QR.';
            $profileComplete = $profile && collect($profile->only(['rfc', 'legal_name', 'fiscal_email', 'tax_regime_code', 'postal_code', 'default_product_service_code', 'default_unit_code', 'default_tax_object_code']))->every(fn ($value) => filled($value));
            if ($profileComplete && $profile->status === 'ready') {
                try {
                    $this->catalogs->assertProfile($profile->only(['rfc', 'tax_regime_code', 'postal_code', 'default_product_service_code', 'default_unit_code', 'default_tax_object_code']));
                    $certificateReady = $profile->certificates()->where('tenant_id', $tenant->id)
                        ->where('rfc', $profile->rfc)->where('status', 'active')
                        ->where('valid_from', '<=', now())->where('valid_until', '>', now())->exists();
                    $providerReady = config('fiscal.enabled') && $profile->registrations()->where('tenant_id', $tenant->id)
                        ->where('provider', config('fiscal.provider'))->where('environment', config('fiscal.environment'))
                        ->where('remote_identifier', $profile->rfc)->where('status', 'active')->exists();
                    $fiscalReady = $certificateReady && $providerReady;
                    if (! $certificateReady) {
                        $code = 'certificate_unavailable';
                        $message = 'Carga un Certificado de Sello Digital vigente en Facturación fiscal.';
                    } elseif (! $providerReady) {
                        $code = 'provider_unavailable';
                        $message = 'Completa la conexión de facturación en Facturación fiscal.';
                    }
                } catch (DomainException|ValidationException) {
                    $message = 'Revisa tus datos fiscales: hay códigos SAT que requieren actualización.';
                }
            }

            $branches = $tenant->branches()->where('is_active', true)->where('billing_enabled', true)->get();
            foreach ($branches as $branch) {
                $fields = $this->missingBranchFields($branch);
                if ($fields !== []) {
                    $missing[] = ['id' => $branch->public_id, 'name' => $branch->name, 'missing_fields' => $fields];
                }
            }
            $branchesReady = $branches->isNotEmpty() && $missing === [];
            if ($fiscalReady) {
                $code = $branchesReady ? 'ready' : 'branches_incomplete';
                $message = $branchesReady ? 'Todo está listo para activar el QR.' : 'Completa la dirección de todas tus sucursales operativas antes de activar el QR.';
            }
        }

        $ready = $allowed && $fiscalReady && $branchesReady;
        return [
            'ready' => $ready, 'enabled' => $ready && $requested, 'requested' => $requested,
            'code' => $code, 'message' => $message,
            'fiscal_ready' => $fiscalReady, 'branches_ready' => $branchesReady,
            'fiscal_url' => '/'.rawurlencode($tenant->slug).'/settings/fiscal',
            'branches_url' => '/'.rawurlencode($tenant->slug).'/settings/branches',
            'missing_branches' => $missing,
        ];
    }

    public function branchReady(Tenant $tenant, Branch $branch): bool
    {
        return $branch->tenant_id === $tenant->id && $branch->is_active && $branch->billing_enabled
            && $this->missingBranchFields($branch) === [];
    }

    private function missingBranchFields(Branch $branch): array
    {
        $missing = [];
        foreach (['postal_code', 'timezone', 'city', 'neighborhood', 'street', 'exterior_number'] as $field) {
            if (blank($branch->getAttribute($field))) {
                $missing[] = $field;
            }
        }
        if (filled($branch->postal_code)) {
            try {
                $postal = $this->catalogs->resolvePostalCode($branch->postal_code);
                if (! $postal) {
                    $missing[] = 'postal_code';
                } elseif ($this->timezones->resolve($postal) !== $branch->timezone) {
                    $missing[] = 'timezone';
                }
            } catch (DomainException|ValidationException) {
                $missing[] = 'postal_code';
            }
        }
        return array_values(array_unique($missing));
    }
}
