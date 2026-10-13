<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table
                ->boolean('billing_enabled')
                ->default(true)
                ->after('is_active');

            $table->index([
                'tenant_id',
                'is_active',
                'billing_enabled',
            ], 'categories_tenant_active_billing_index');
        });

        Schema::table('products', function (Blueprint $table) {
            $table
                ->boolean('billing_enabled')
                ->default(true)
                ->after('is_active');

            $table->index([
                'tenant_id',
                'category_id',
                'is_active',
                'billing_enabled',
            ], 'products_tenant_category_active_billing_index');

            $table->index([
                'tenant_id',
                'is_active',
                'billing_enabled',
            ], 'products_tenant_active_billing_index');
        });

        $this->syncExistingCatalogs();
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_tenant_active_billing_index');
            $table->dropIndex('products_tenant_category_active_billing_index');
            $table->dropColumn('billing_enabled');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex('categories_tenant_active_billing_index');
            $table->dropColumn('billing_enabled');
        });
    }

    private function syncExistingCatalogs(): void
    {
        DB::table('plan_subscriptions')
            ->whereNotNull('current_tenant_id')
            ->whereColumn('current_tenant_id', 'tenant_id')
            ->orderBy('id')
            ->get([
                'tenant_id',
                'entitlements_snapshot',
            ])
            ->each(function ($subscription): void {
                $entitlements = json_decode(
                    (string) $subscription->entitlements_snapshot,
                    true
                );

                if (! is_array($entitlements)) {
                    return;
                }

                $categoryIds = DB::table('categories')
                    ->where('tenant_id', $subscription->tenant_id)
                    ->whereNull('deleted_at')
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->orderBy('id')
                    ->pluck('id');

                $allowedCategoryIds = $this->takeLimit(
                    $categoryIds,
                    $entitlements['max_categories'] ?? 0,
                );

                DB::table('categories')
                    ->where('tenant_id', $subscription->tenant_id)
                    ->whereNull('deleted_at')
                    ->update(['billing_enabled' => false]);

                if ($allowedCategoryIds !== []) {
                    DB::table('categories')
                        ->where('tenant_id', $subscription->tenant_id)
                        ->whereIn('id', $allowedCategoryIds)
                        ->update(['billing_enabled' => true]);
                }

                $productIds = DB::table('products')
                    ->where('tenant_id', $subscription->tenant_id)
                    ->whereNull('deleted_at')
                    ->when(
                        $allowedCategoryIds === [],
                        fn ($query) => $query->whereRaw('1 = 0'),
                        fn ($query) => $query->whereIn('category_id', $allowedCategoryIds),
                    )
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->orderBy('id')
                    ->pluck('id');

                $allowedProductIds = $this->takeLimit(
                    $productIds,
                    $entitlements['max_products'] ?? 0,
                );

                DB::table('products')
                    ->where('tenant_id', $subscription->tenant_id)
                    ->whereNull('deleted_at')
                    ->update(['billing_enabled' => false]);

                if ($allowedProductIds !== []) {
                    DB::table('products')
                        ->where('tenant_id', $subscription->tenant_id)
                        ->whereIn('id', $allowedProductIds)
                        ->update(['billing_enabled' => true]);
                }
            });
    }

    private function takeLimit($ids, mixed $limit): array
    {
        if ($limit === null) {
            return $ids->all();
        }

        return $ids
            ->take(max(0, (int) $limit))
            ->all();
    }
};
