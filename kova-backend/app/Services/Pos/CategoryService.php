<?php

namespace App\Services\Pos;

use App\Models\Category;
use App\Models\Tenant;
use App\Services\Plans\PlanLimitService;
use App\Services\Subscriptions\CatalogAccessService;
use App\Services\Subscriptions\EntitlementService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CategoryService
{
    public function __construct(
        private readonly PlanLimitService $limits,
        private readonly CatalogAccessService $catalogAccess,
    ) {}

    public function list(
        Tenant $tenant
    ): Collection {
        return $tenant
            ->categories()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function operationalList(Tenant $tenant): Collection
    {
        return $tenant->categories()
            ->where('is_active', true)
            ->where('billing_enabled', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function create(Tenant $tenant, array $data): Category
    {
        return DB::transaction(function () use ($tenant, $data) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(EntitlementService::class)->assertWritable($tenant);

            return $this->createWithinLimit($tenant, $data);
        });
    }

    private function createWithinLimit(
        Tenant $tenant,
        array $data
    ): Category {
        /*
         * Primero validamos el límite
         * permitido por el plan.
         */
        $this->limits->assertCanCreateCategory(
            $tenant
        );

        /*
         * Después validamos que no exista
         * otra categoría con el mismo nombre.
         */
        $this->assertUniqueName(
            tenant: $tenant,
            name: $data['name'],
        );

        return $tenant
            ->categories()
            ->create([
                'name' => trim($data['name']),

                'sort_order' => $data['sort_order'] ?? 0,

                'is_active' => $data['is_active'] ?? true,

                'billing_enabled' => true,
            ])->refresh();
    }

    public function update(
        Tenant $tenant,
        Category $category,
        array $data
    ): Category {
        $this->catalogAccess->assertCategoryMutable(
            $category
        );

        if (isset($data['name'])) {
            $this->assertUniqueName(
                tenant: $tenant,
                name: $data['name'],
                ignoreId: $category->id,
            );
        }

        $category->update([
            ...$data,

            'name' => isset($data['name'])
                ? trim($data['name'])
                : $category->name,
        ]);

        return $category->refresh();
    }

    public function delete(
        Category $category
    ): void {
        $this->catalogAccess->assertCategoryMutable(
            $category
        );

        if ($category->products()->exists()) {
            throw ValidationException::withMessages([
                'category' => 'La categoría tiene productos asociados.',
            ]);
        }

        $category->delete();
    }

    public function findForTenant(
        Tenant $tenant,
        string $publicId
    ): Category {
        return $tenant
            ->categories()
            ->where(
                'public_id',
                $publicId
            )
            ->firstOrFail();
    }

    private function assertUniqueName(
        Tenant $tenant,
        string $name,
        ?int $ignoreId = null
    ): void {
        $query = $tenant
            ->categories()
            ->where(
                'name',
                trim($name)
            );

        if ($ignoreId !== null) {
            $query->whereKeyNot(
                $ignoreId
            );
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'Ya existe una categoría con este nombre.',
            ]);
        }
    }
}
