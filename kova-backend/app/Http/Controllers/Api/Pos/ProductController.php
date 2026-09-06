<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\StoreProductRequest;
use App\Http\Requests\Pos\UpdateProductRequest;
use App\Http\Resources\Pos\ProductResource;
use App\Models\Product;
use App\Services\Pos\ProductService;
use App\Services\Taxes\ProductTaxService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $products,
        private readonly ProductTaxService $taxes,
        private readonly CurrentTenant $currentTenant,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize(
            'viewAny',
            Product::class
        );

        $tenant =
            $this->currentTenant->get();

        $products =
            $this->products->list(
                $tenant
            );

        request()->attributes->set(
            'default_tax',
            $this->taxes->defaultTax(
                $tenant
            ),
        );

        return response()->json([
            'data' =>
            ProductResource::collection(
                $products
            ),
        ]);
    }

    public function store(
        StoreProductRequest $request
    ): JsonResponse {
        Gate::authorize(
            'create',
            Product::class
        );

        $tenant =
            $this->currentTenant->get();

        $product =
            $this->products->create(
                tenant: $tenant,

                data: $request->validated(),
            );

        request()->attributes->set(
            'default_tax',
            $this->taxes->defaultTax(
                $tenant
            ),
        );

        return response()->json([
            'data' =>
            new ProductResource(
                $product
            ),
        ], 201);
    }

    public function update(
        UpdateProductRequest $request,
        string $product
    ): JsonResponse {
        $tenant =
            $this->currentTenant->get();

        $productModel =
            $this->products
            ->findForTenant(
                tenant: $tenant,
                publicId: $product,
            );

        Gate::authorize(
            'update',
            $productModel
        );

        $product =
            $this->products->update(
                tenant: $tenant,
                product: $productModel,
                data: $request->validated(),
            );

        request()->attributes->set(
            'default_tax',
            $this->taxes->defaultTax(
                $tenant
            ),
        );

        return response()->json([
            'data' =>
            new ProductResource(
                $product
            ),
        ]);
    }

    public function destroy(
        string $product
    ): JsonResponse {
        $tenant =
            $this->currentTenant->get();

        $productModel =
            $this->products
            ->findForTenant(
                tenant: $tenant,
                publicId: $product,
            );

        Gate::authorize(
            'delete',
            $productModel
        );

        $this->products->delete(
            $productModel
        );

        return response()->json([
            'message' =>
            'Producto eliminado correctamente.',
        ]);
    }
}
