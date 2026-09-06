<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreTaxRequest;
use App\Http\Requests\Settings\UpdateTaxConfigRequest;
use App\Http\Requests\Settings\UpdateTaxRequest;
use App\Http\Resources\TaxResource;
use App\Models\Tax;
use App\Services\Taxes\TaxSettingsService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TaxController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly TaxSettingsService $taxes,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Tax::class);

        return response()->json([
            'data' => TaxResource::collection(
                $this->taxes->list(
                    $this->currentTenant->get()
                )
            ),
        ]);
    }

    public function store(StoreTaxRequest $request): JsonResponse
    {
        Gate::authorize('create', Tax::class);

        $tax = $this->taxes->create(
            tenant: $this->currentTenant->get(),
            data: $request->validated(),
        );

        return response()->json([
            'data' => new TaxResource($tax),
        ], 201);
    }

    public function update(
        UpdateTaxRequest $request,
        string $tax,
    ): JsonResponse {
        $tenant = $this->currentTenant->get();
        $taxModel = $this->taxes->findForTenant($tenant, $tax);

        Gate::authorize('update', $taxModel);

        $taxModel = $this->taxes->update(
            tenant: $tenant,
            tax: $taxModel,
            data: $request->validated(),
        );

        return response()->json([
            'data' => new TaxResource($taxModel),
        ]);
    }

    public function config(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('taxes.view'), 403);

        $setting = $this->taxes->setting(
            $this->currentTenant->get()
        );

        return response()->json([
            'data' => [
                'default_tax' => new TaxResource(
                    $setting->defaultTax
                ),
                'prices_include_tax' => $setting->prices_include_tax,
            ],
        ]);
    }

    public function updateConfig(
        UpdateTaxConfigRequest $request,
    ): JsonResponse {
        abort_unless($request->user()?->can('taxes.manage'), 403);

        $setting = $this->taxes->updateConfig(
            tenant: $this->currentTenant->get(),
            defaultTaxPublicId: $request->string('default_tax_id')->toString(),
        );

        return response()->json([
            'data' => [
                'default_tax' => new TaxResource(
                    $setting->defaultTax
                ),
                'prices_include_tax' => $setting->prices_include_tax,
            ],
        ]);
    }
}
