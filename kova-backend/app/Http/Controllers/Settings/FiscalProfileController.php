<?php

namespace App\Http\Controllers\Settings;

use App\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Services\Fiscal\CsdVault;
use App\Services\Fiscal\FiscalProfileService;
use App\Services\Fiscal\SatCatalogService;
use App\Services\Subscriptions\EntitlementService;
use App\Tenancy\CurrentTenant;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FiscalProfileController extends Controller
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /*
    |--------------------------------------------------------------------------
    | Show
    |--------------------------------------------------------------------------
    */

    public function show(Request $request, CurrentTenant $currentTenant, FiscalProfileService $profiles): JsonResponse
    {
        abort_unless($request->user()?->can(PermissionName::FiscalView->value), 403);
        $this->assertFiscalAvailable($currentTenant);
        return response()->json(['data' => $profiles->publicData($currentTenant->get())]);
    }

    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */

    public function update(Request $request, CurrentTenant $currentTenant, FiscalProfileService $profiles, SatCatalogService $catalogs): JsonResponse
    {
        abort_unless($request->user()?->can(PermissionName::FiscalManage->value), 403);
        $this->assertFiscalAvailable($currentTenant);

        $data = $request->validate(['rfc' => ['required', 'string', 'regex:/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/i'], 'legal_name' => ['required', 'string', 'max:254'], 'tax_regime_code' => ['required', 'string', 'size:3'], 'postal_code' => ['required', 'digits:5'], 'fiscal_email' => ['required', 'email:rfc', 'max:254'], 'default_product_service_code' => ['required', 'digits:8'], 'default_unit_code' => ['required', 'string', 'max:30'], 'default_tax_object_code' => ['required', 'string', 'max:30'], 'default_series' => ['prohibited'], 'next_folio' => ['prohibited'], 'default_unit_name' => ['prohibited']]);

        $data['rfc'] = strtoupper(trim($data['rfc']));
        $data['legal_name'] = trim($data['legal_name']);
        $data['tax_regime_code'] = trim($data['tax_regime_code']);
        $data['postal_code'] = trim($data['postal_code']);
        $data['fiscal_email'] = strtolower(trim($data['fiscal_email']));
        $data['default_product_service_code'] = trim($data['default_product_service_code']);
        $data['default_unit_code'] = strtoupper(trim($data['default_unit_code']));
        $data['default_tax_object_code'] = trim($data['default_tax_object_code']);
        $catalogs->assertProfile($data);
        $unit = $catalogs->resolve('sat_units', $data['default_unit_code']);
        $data['default_unit_name'] = $unit['description'];
        $profiles->update($currentTenant->get(), $data, $request);
        return response()->json(['data' => $profiles->publicData($currentTenant->get())]);
    }

    /*
    |--------------------------------------------------------------------------
    | Certificate
    |--------------------------------------------------------------------------
    */

    public function certificate(Request $request, CurrentTenant $currentTenant, FiscalProfileService $profiles, CsdVault $vault, SatCatalogService $catalogs): JsonResponse
    {
        abort_unless($request->user()?->can(PermissionName::FiscalManage->value), 403);
        $this->assertFiscalAvailable($currentTenant);

        $data = $request->validate(['certificate' => ['required', 'file', 'max:1024', 'extensions:cer'], 'private_key' => ['required', 'file', 'max:1024', 'extensions:key'], 'password' => ['required', 'string', 'max:512']]);

        $tenant = $currentTenant->get();
        $profile = $profiles->profile($tenant);
        $catalogs->assertProfile($profile->only(['rfc', 'tax_regime_code', 'postal_code', 'default_product_service_code', 'default_unit_code', 'default_tax_object_code']));

        try {
            $vault->store($profile, $data['certificate'], $data['private_key'], $data['password']);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        try {
            $profiles->syncProvider($tenant);
        } catch (DomainException $exception) {
            report($exception);
            return response()->json(['data' => $profiles->publicData($tenant), 'warning' => 'El Certificado de Sello Digital se guardó correctamente, pero no fue posible activar la facturación en este momento.'], 201);
        }

        return response()->json(['data' => $profiles->publicData($tenant)], 201);
    }

    /*
    |--------------------------------------------------------------------------
    | Provider sync
    |--------------------------------------------------------------------------
    */

    public function sync(Request $request, CurrentTenant $currentTenant, FiscalProfileService $profiles): JsonResponse
    {
        abort_unless($request->user()?->can(PermissionName::FiscalManage->value), 403);
        $this->assertFiscalAvailable($currentTenant);

        try {
            $profiles->syncProvider($currentTenant->get());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $profiles->publicData($currentTenant->get())]);
    }

    /*
    |--------------------------------------------------------------------------
    | Plan access
    |--------------------------------------------------------------------------
    */

    private function assertFiscalAvailable(CurrentTenant $currentTenant): void
    {
        abort_unless($this->entitlements->allows($currentTenant->get(), 'allows_invoicing'), 403, 'Tu plan actual no incluye facturación fiscal.');
    }
}
