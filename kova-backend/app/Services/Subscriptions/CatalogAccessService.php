<?php

namespace App\Services\Subscriptions;

use App\Exceptions\PlanLimitExceededException;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

final class CatalogAccessService
{
    public function __construct(
        private readonly EntitlementService $entitlements,
    ) {}

    public function syncTenant(Tenant $tenant): void
    {
        DB::transaction(function () use ($tenant): void {
            Tenant::query()
                ->whereKey($tenant->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->syncCategories($tenant);
            $this->syncProducts($tenant);
        });
    }

    public function usage(Tenant $tenant): array
    {
        $productLimit = $this->limit($tenant, 'max_products');
        $categoryLimit = $this->limit($tenant, 'max_categories');

        $productsUsed = $this->operationalProductsCount($tenant);
        $categoriesUsed = $this->operationalCategoriesCount($tenant);

        return [
            'products' => $this->usagePayload($productsUsed, $productLimit),
            'categories' => $this->usagePayload($categoriesUsed, $categoryLimit),
        ];
    }

    public function assertCanCreateProduct(Tenant $tenant): void
    {
        $this->entitlements->assertWritable($tenant);

        $limit = $this->limit($tenant, 'max_products');

        if ($limit === null) {
            return;
        }

        if ($this->operationalProductsCount($tenant) >= $limit) {
            throw new PlanLimitExceededException(
                sprintf(
                    'Tu plan %s permite un máximo de %d productos operativos.',
                    $this->planName($tenant),
                    $limit,
                )
            );
        }
    }

    public function assertCanCreateCategory(Tenant $tenant): void
    {
        $this->entitlements->assertWritable($tenant);

        $historicalLimit = 1000;
        $historicalCategories = $tenant
            ->categories()
            ->withTrashed()
            ->count();

        if ($historicalCategories >= $historicalLimit) {
            throw new PlanLimitExceededException(
                'Se alcanzó el límite técnico de categorías para este negocio.'
            );
        }

        $limit = $this->limit($tenant, 'max_categories');

        if ($limit === null) {
            return;
        }

        if ($this->operationalCategoriesCount($tenant) >= $limit) {
            throw new PlanLimitExceededException(
                sprintf(
                    'Tu plan %s permite un máximo de %d categorías operativas.',
                    $this->planName($tenant),
                    $limit,
                )
            );
        }
    }

    public function assertCategoryMutable(Category $category): void
    {
        if (! $category->billing_enabled) {
            throw new PlanLimitExceededException(
                'Esta categoría está bloqueada por tu plan actual. Reactiva o sube tu plan para volver a editarla.'
            );
        }
    }

    public function assertProductMutable(Product $product): void
    {
        $product->loadMissing('category');

        if (! $product->billing_enabled || ! $product->category?->billing_enabled) {
            throw new PlanLimitExceededException(
                'Este producto está bloqueado por tu plan actual. Reactiva o sube tu plan para volver a editarlo.'
            );
        }
    }

    public function assertCategoryUsableForProducts(Category $category): void
    {
        if (! $category->billing_enabled) {
            throw new PlanLimitExceededException(
                'Esta categoría está bloqueada por tu plan actual. Elige una categoría operativa.'
            );
        }
    }

    public function assertProductUsable(Product $product): void
    {
        $product->loadMissing('category');

        if (
            ! $product->is_active
            || ! $product->billing_enabled
            || ! $product->category
            || ! $product->category->is_active
            || ! $product->category->billing_enabled
        ) {
            throw new PlanLimitExceededException(
                'Uno o más productos no están disponibles con tu plan actual.'
            );
        }
    }

    public function productCapabilities(Product $product): array
    {
        $product->loadMissing('category');

        $locked = ! $product->billing_enabled
            || ! $product->category
            || ! $product->category->billing_enabled;

        return [
            'billing_enabled' => (bool) $product->billing_enabled,
            'locked_by_plan' => $locked,
            'can_edit' => ! $locked,
            'can_delete' => ! $locked,
            'can_use' => ! $locked
                && (bool) $product->is_active
                && (bool) $product->category?->is_active,
            'lock_reason' => $locked
                ? 'Tu plan actual no incluye este producto dentro del cupo operativo.'
                : null,
        ];
    }

    public function categoryCapabilities(Category $category): array
    {
        $locked = ! $category->billing_enabled;

        return [
            'billing_enabled' => (bool) $category->billing_enabled,
            'locked_by_plan' => $locked,
            'can_edit' => ! $locked,
            'can_delete' => ! $locked,
            'can_use' => ! $locked && (bool) $category->is_active,
            'lock_reason' => $locked
                ? 'Tu plan actual no incluye esta categoría dentro del cupo operativo.'
                : null,
        ];
    }

    private function syncCategories(Tenant $tenant): void
    {
        $ids = $tenant
            ->categories()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->pluck('id');

        $limit = $this->limit($tenant, 'max_categories');
        $allowed = $limit === null
            ? $ids
            : $ids->take($limit);

        $tenant->categories()->update(['billing_enabled' => false]);

        if ($allowed->isNotEmpty()) {
            $tenant
                ->categories()
                ->whereIn('id', $allowed->all())
                ->update(['billing_enabled' => true]);
        }
    }

    private function syncProducts(Tenant $tenant): void
    {
        $ids = $tenant
            ->products()
            ->whereHas(
                'category',
                fn ($query) => $query->where('billing_enabled', true)
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->pluck('id');

        $limit = $this->limit($tenant, 'max_products');
        $allowed = $limit === null
            ? $ids
            : $ids->take($limit);

        $tenant->products()->update(['billing_enabled' => false]);

        if ($allowed->isNotEmpty()) {
            $tenant
                ->products()
                ->whereIn('id', $allowed->all())
                ->update(['billing_enabled' => true]);
        }
    }

    private function operationalProductsCount(Tenant $tenant): int
    {
        return $tenant
            ->products()
            ->where('billing_enabled', true)
            ->whereHas(
                'category',
                fn ($query) => $query->where('billing_enabled', true)
            )
            ->count();
    }

    private function operationalCategoriesCount(Tenant $tenant): int
    {
        return $tenant
            ->categories()
            ->where('billing_enabled', true)
            ->count();
    }

    private function limit(Tenant $tenant, string $feature): ?int
    {
        $subscription = $this->entitlements->current($tenant);

        if (! $subscription) {
            throw new \DomainException('El negocio no tiene contrato vigente.');
        }

        $values = $this->entitlements->resolve($subscription);
        // null is an unlimited entitlement, not a missing feature.
        $value = array_key_exists($feature, $values) ? $values[$feature] : 0;

        return $value === null ? null : (int) $value;
    }

    private function planName(Tenant $tenant): string
    {
        return $this->entitlements->current($tenant)?->version?->name ?? 'actual';
    }

    private function usagePayload(int $used, ?int $limit): array
    {
        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
            'is_unlimited' => $limit === null,
            'limit_reached' => $limit !== null && $used >= $limit,
        ];
    }
}
