<?php

namespace App\Http\Controllers\Branches;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Branches\BranchAccessService;
use App\Services\Branches\BranchProductService;
use App\Services\Pos\ProductService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class BranchProductController extends Controller
{
    public function show(Request $request, Branch $branch, string $product, CurrentTenant $current, ProductService $products, BranchAccessService $access, BranchProductService $settings): JsonResponse
    {
        $tenant = $current->get();
        $model = $products->findForTenant($tenant, $product);
        Gate::authorize('view', $model);
        $access->assertHistory($request->user(), $tenant, $branch, 'products.view');
        $model->load(['category', 'branchSettings' => fn ($query) => $query->where('branch_id', $branch->id)]);
        $settings->applyEffective($model);
        $override = $model->branchSettings->first();

        return response()->json(['data' => [
            'branch_id' => $branch->public_id,
            'product_id' => $model->public_id,
            'mode' => $override ? 'override' : 'inherit',
            'is_available' => (bool) $model->branch_available,
            'price' => $model->effective_price,
            'override' => $override ? [
                'is_available' => (bool) $override->is_available,
                'price_override' => $override->price_override,
            ] : null,
        ]]);
    }

    public function update(Request $request, Branch $branch, string $product, CurrentTenant $current, ProductService $products, BranchAccessService $access, BranchProductService $settings): JsonResponse
    {
        $tenant = $current->get();
        $model = $products->findForTenant($tenant, $product);
        Gate::authorize('update', $model);
        $access->assertHistory($request->user(), $tenant, $branch, 'products.update');
        $data = $request->validate(['is_available' => ['required', 'boolean'], 'price_override' => ['present', 'nullable', 'numeric', 'min:0', 'max:9999999999.99']]);
        $setting = $settings->update($tenant, $branch, $model, $data);

        return response()->json(['data' => ['branch_id' => $branch->public_id, 'product_id' => $model->public_id, 'is_available' => $setting->is_available, 'price_override' => $setting->price_override]]);
    }

    public function destroy(Request $request, Branch $branch, string $product, CurrentTenant $current, ProductService $products, BranchAccessService $access, BranchProductService $settings): JsonResponse
    {
        $tenant = $current->get();
        $model = $products->findForTenant($tenant, $product);
        Gate::authorize('update', $model);
        $access->assertHistory($request->user(), $tenant, $branch, 'products.update');
        $settings->reset($tenant, $branch, $model);

        return response()->json(['message' => 'La configuración del producto volvió a heredar los valores del negocio.']);
    }
}
