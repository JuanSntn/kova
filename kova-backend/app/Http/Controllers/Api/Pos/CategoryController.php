<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\StoreCategoryRequest;
use App\Http\Requests\Pos\UpdateCategoryRequest;
use App\Http\Resources\Pos\CategoryResource;
use App\Models\Category;
use App\Services\Pos\CategoryService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class CategoryController extends Controller
{
    public function __construct(
        private readonly CategoryService $service,
        private readonly CurrentTenant $currentTenant,
    ) {}

    public function index(): JsonResponse
    {
        $tenant = $this->currentTenant->get();

        $categories = $this->service->list($tenant);

        return response()->json([
            'data' => CategoryResource::collection($categories),
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
