<?php

namespace App\Services\Fiscal;

use App\Contracts\Fiscal\PacProvider;
use App\Domain\Audit\AuditRecorder;
use App\Models\FiscalSeries;
use App\Models\Tenant;
use App\Models\TenantFiscalProfile;
use App\Models\TenantPacRegistration;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class FiscalProfileService
{
    public function __construct(private PacProvider $pac, private AuditRecorder $audit, private SatCatalogService $catalogs, private SatConfigurationAuditor $auditor) {}

    public function profile(Tenant $tenant): TenantFiscalProfile
    {
        return TenantFiscalProfile::query()->firstOrCreate(['tenant_id' => $tenant->id], ['status' => 'incomplete', 'default_series' => 'A']);
    }

    public function update(Tenant $tenant, array $data, Request $request): TenantFiscalProfile
    {
        return DB::transaction(function () use ($tenant, $data, $request) {
            $profile = TenantFiscalProfile::query()
                ->where('tenant_id', $tenant->id)
                ->lockForUpdate()
                ->first() ?? new TenantFiscalProfile([
                    'tenant_id' => $tenant->id,
                ]);

            $before = $profile->exists
                ? $profile->only([
                    'rfc',
                    'legal_name',
                    'tax_regime_code',
                    'postal_code',
                    'status',
                ])
                : [];

            $profile->fill([
                ...$data,
                'rfc' => strtoupper(trim($data['rfc'])),
                'legal_name' => mb_strtoupper(trim($data['legal_name'])),
                'fiscal_email' => strtolower(trim($data['fiscal_email'])),
                'default_series' => $profile->default_series ?: 'A',
                'status' => 'pending_validation',
                'validated_at' => null,
                'blocked_reason_code' => null,
            ])->save();

            $this->audit->record(
                $request,
                'fiscal.profile_updated',
                $profile,
                $before,
                $profile->only([
                    'rfc',
                    'legal_name',
                    'tax_regime_code',
                    'postal_code',
                    'status',
                ])
            );

            return $profile->refresh();
        });
    }

    public function syncProvider(Tenant $tenant): TenantPacRegistration
    {
        if (! config('fiscal.enabled')) {
            throw new DomainException('Activa la integración fiscal antes de conectar al PAC.');
        }
        $profile = $this->profile($tenant);
        $this->catalogs->assertProfile($profile->only(['rfc', 'tax_regime_code', 'postal_code', 'default_product_service_code', 'default_unit_code', 'default_tax_object_code']));
        if (! $profile->rfc || ! $profile->certificates()->where('status', 'active')->where('valid_until', '>', now())->exists()) {
            throw new DomainException('Completa el perfil y carga un CSD vigente antes de registrar al emisor.');
        }
        $environment = (string) config('fiscal.environment');
        $registration = TenantPacRegistration::query()->firstOrCreate([
            'tenant_id' => $tenant->id,
            'provider' => (string) config('fiscal.provider'),
            'environment' => $environment,
        ], ['tenant_fiscal_profile_id' => $profile->id, 'status' => 'pending']);

        $remote = $this->pac->issuerStatus($profile->rfc);
        if ($remote->code === 'not_registered') {
            $remote = $this->pac->registerIssuer($profile->rfc);
        }
        $registration->update([
            'tenant_fiscal_profile_id' => $profile->id,
            'remote_identifier' => $profile->rfc,
            'status' => $remote->successful ? 'active' : 'failed',
            'last_synced_at' => now(),
            'last_error_code' => $remote->successful ? null : $remote->code,
            'last_error_message' => $remote->successful ? null : $remote->message,
        ]);
        $profile->update([
            'status' => $remote->successful ? 'ready' : 'blocked',
            'validated_at' => $remote->successful ? now() : null,
            'blocked_reason_code' => $remote->successful ? null : $remote->code,
        ]);

        return $registration->refresh();
    }

    public function publicData(Tenant $tenant): array
    {
        $profile = $this->profile($tenant)->load(['certificates' => fn($query) => $query->latest('id'), 'registrations' => fn($query) => $query->latest('id')]);
        $certificate = $profile->certificates->first();
        $registration = $profile->registrations->first();
        $audit = $this->auditor->audit($tenant, $profile);

        return [
            'id' => $profile->public_id,
            'rfc' => $profile->rfc,
            'legal_name' => $profile->legal_name,
            'tax_regime_code' => $profile->tax_regime_code,
            'postal_code' => $profile->postal_code,
            'fiscal_email' => $profile->fiscal_email,
            'default_series' => $profile->default_series,
            'default_product_service_code' => $profile->default_product_service_code,
            'default_unit_code' => $profile->default_unit_code,
            'default_unit_name' => $profile->default_unit_name,
            'default_tax_object_code' => $profile->default_tax_object_code,
            'tax_regime' => $this->safeResolve('sat_tax_regimes', $profile->tax_regime_code),
            'default_product_service' => $this->safeResolve('sat_product_services', $profile->default_product_service_code),
            'default_unit' => $this->safeResolve('sat_units', $profile->default_unit_code),
            'default_tax_object' => $this->safeResolve('sat_tax_objects', $profile->default_tax_object_code),
            'configuration_valid' => $audit['valid'],
            'validation_issues' => $audit['issues'],
            'catalog_version' => $audit['catalog_version'],
            'next_folio' => FiscalSeries::query()->where('series', $profile->default_series)->value('next_folio') ?? 1,
            'status' => $profile->status,
            'certificate' => $certificate ? ['id' => $certificate->public_id, 'serial_number' => $certificate->serial_number, 'valid_from' => $certificate->valid_from, 'valid_until' => $certificate->valid_until, 'status' => $certificate->status] : null,
            'provider' => $registration ? ['name' => $registration->provider, 'environment' => $registration->environment, 'status' => $registration->status, 'last_synced_at' => $registration->last_synced_at, 'error_code' => $registration->last_error_code] : null,
        ];
    }

    private function safeResolve(string $table, ?string $code): ?array
    {
        if (! $code) {
            return null;
        }
        try {
            return $this->catalogs->resolve($table, $code);
        } catch (DomainException) {
            return null;
        }
    }
}
