<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\StoreCategoryRequest;
use App\Http\Requests\Pos\UpdateCategoryRequest;
use App\Http\Resources\Pos\CategoryResource;
use App\Models\Category;
use App\Services\Pos\CategoryService;
use App\Services\Subscriptions\CatalogAccessService;
use App\Services\Subscriptions\EntitlementService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class CategoryController extends Controller
{
    public function __construct(
        private readonly CategoryService $service,
        private readonly CurrentTenant $currentTenant,
        private readonly CatalogAccessService $catalogAccess,
        private readonly EntitlementService $entitlements,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Category::class);

        $tenant = $this->currentTenant->get();

        $categories = $this->service->list($tenant);

        $subscription = $this->entitlements->current($tenant);
        $usage = $this->catalogAccess->usage($tenant);

        return response()->json([
            'data' => CategoryResource::collection($categories),
            'meta' => [
                'plan' => [
                    'code' => (string) ($subscription?->version?->plan?->code ?? ''),
                    'name' => (string) ($subscription?->version?->name ?? 'Plan actual'),
                ],
                'usage' => $usage['categories'],
            ],
        ]);
    }

    public function operationalIndex(): JsonResponse
    {
        return response()->json([
            'data' => CategoryResource::collection(
                $this->service->operationalList($this->currentTenant->get()),
            ),
        ]);
    }

    public function store(
        StoreCategoryRequest $request
    ): JsonResponse {
        Gate::authorize('create', Category::class);

        $category = $this->service->create(
            tenant: $this->currentTenant->get(),
            data: $request->validated(),
        );

        return response()->json([
            'data' => new CategoryResource($category),
        ], 201);
    }

    public function update(
        UpdateCategoryRequest $request,
        string $category
    ): JsonResponse {
        $tenant = $this->currentTenant->get();

        $categoryModel = $this->service->findForTenant(
            tenant: $tenant,
            publicId: $category,
        );

        Gate::authorize('update', $categoryModel);

        $categoryModel = $this->service->update(
            tenant: $tenant,
            category: $categoryModel,
            data: $request->validated(),
        );

        return response()->json([
            'data' => new CategoryResource($categoryModel),
        ]);
    }

    public function destroy(
        string $category
    ): JsonResponse {
        $tenant = $this->currentTenant->get();

        $categoryModel = $this->service->findForTenant(
            tenant: $tenant,
            publicId: $category,
        );

        Gate::authorize('delete', $categoryModel);

        $this->service->delete($categoryModel);

        return response()->json([
            'message' => 'Categoría eliminada correctamente.',
        ]);
    }
}
