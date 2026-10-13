<?php

namespace App\Http\Controllers\Branches;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\TenantFiscalProfile;
use App\Services\Branches\BranchAccessService;
use App\Services\Fiscal\BranchFiscalSettingsService;
use App\Services\Fiscal\FiscalSeriesService;
use App\Services\Subscriptions\EntitlementService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BranchFiscalController extends Controller
{
    public function __construct(
        private readonly EntitlementService $entitlements,
    ) {}

    public function show(
        Request $request,
        Branch $branch,
        CurrentTenant $current,
        BranchAccessService $access,
        BranchFiscalSettingsService $settings,
        FiscalSeriesService $series,
    ): JsonResponse {
        $tenant = $current->get();
        $access->assertHistory($request->user(), $tenant, $branch, 'fiscal.view');
        $this->assertInvoicingIsAvailable($tenant);
        $profile = TenantFiscalProfile::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $effective = $settings->effectiveSeries($tenant, $branch, $profile->default_series);
        $configuration = $series->configuration($tenant);
        $effectiveData = collect($configuration['series'])->firstWhere('series', $effective->series);

        abort_unless($effectiveData, 409, 'La serie fiscal efectiva no está disponible.');

        return response()->json(['data' => [
            'mode' => $branch->fiscalSetting()->exists() ? 'override' : 'inherit',
            'default_series' => $configuration['default_series'],
            'effective_series' => $effective->series,
            'effective_next_folio' => $effectiveData['next_folio'],
            'effective_next_number' => $effectiveData['next_number'],
            'series' => $configuration['series'],
        ]]);
    }

    public function update(
        Request $request,
        Branch $branch,
        CurrentTenant $current,
        BranchAccessService $access,
        BranchFiscalSettingsService $settings,
        FiscalSeriesService $series,
    ): JsonResponse {
        $tenant = $current->get();
        $access->assertHistory($request->user(), $tenant, $branch, 'fiscal.manage');
        $this->assertInvoicingIsAvailable($tenant);
        $data = $request->validate(['mode' => ['required', 'in:inherit,override'], 'series' => ['nullable', 'required_if:mode,override', 'string', 'max:25', 'prohibited_if:mode,inherit']]);
        $data['mode'] === 'inherit' ? $settings->inherit($tenant, $branch) : $settings->override($tenant, $branch, $data['series']);

        return $this->show($request, $branch->refresh(), $current, $access, $settings, $series);
    }

    private function assertInvoicingIsAvailable(Tenant $tenant): void
    {
        abort_unless(
            $this->entitlements->allows($tenant, 'allows_invoicing'),
            403,
            'Tu plan actual no incluye configuración de series CFDI.',
        );
    }
}
