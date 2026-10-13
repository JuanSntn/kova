<?php

namespace App\Services\Branches;

use App\Models\Branch;
use App\Models\BranchProductSetting;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class BranchProductService
{
    public function list(Tenant $tenant, Branch $branch): Collection
    {
        return $tenant->products()->with(['category', 'tax', 'branchSettings' => fn ($q) => $q->where('branch_id', $branch->id)])
            ->where('is_active', true)->where('billing_enabled', true)
            ->whereHas('category', fn ($q) => $q->where('is_active', true)->where('billing_enabled', true))
            ->orderBy('sort_order')->orderBy('name')->get()->filter(fn (Product $product) => $this->available($product))
            ->each(fn (Product $product) => $this->applyEffective($product));
    }

    public function applyEffective(Product $product): Product
    {
        $setting = $product->branchSettings->first();
        $product->setAttribute('effective_price', $setting?->price_override ?? $product->price);
        $product->setAttribute('branch_available', $setting?->is_available ?? true);

        return $product;
    }

    public function available(Product $product): bool
    {
        $setting = $product->branchSettings->first();

        return (bool) $product->is_active && (bool) $product->billing_enabled
            && (bool) $product->category?->is_active && (bool) $product->category?->billing_enabled
            && ($setting?->is_available ?? true);
    }

    public function update(Tenant $tenant, Branch $branch, Product $product, array $data): BranchProductSetting
    {
        $this->assertSameTenant($tenant, $branch, $product);

        return DB::transaction(fn () => BranchProductSetting::query()->updateOrCreate(
            ['branch_id' => $branch->id, 'product_id' => $product->id],
            ['is_available' => $data['is_available'], 'price_override' => $data['price_override'] ?? null],
        ));
    }

    public function reset(Tenant $tenant, Branch $branch, Product $product): void
    {
        $this->assertSameTenant($tenant, $branch, $product);
        BranchProductSetting::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->delete();
    }

    private function assertSameTenant(Tenant $tenant, Branch $branch, Product $product): void
    {
        abort_unless((int) $branch->tenant_id === (int) $tenant->id && (int) $product->tenant_id === (int) $tenant->id, 404);
    }
}
