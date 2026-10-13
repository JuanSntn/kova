<?php

namespace App\Services\Inventory;

use App\Models\Branch;
use App\Models\Tenant;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class InventoryIntelligenceReportService
{
    public function build(Tenant $tenant, Branch $branch, CarbonImmutable $startsAt, CarbonImmutable $endsAt): array
    {
        $stocks = DB::table('inventory_supplies as supply')
            ->leftJoin('inventory_balances as balance', function ($join) use ($branch) {
                $join->on('balance.supply_id', '=', 'supply.id')->where('balance.branch_id', $branch->id);
            })
            ->where('supply.tenant_id', $tenant->id)
            ->orderBy('supply.name')
            ->get([
                'supply.id', 'supply.public_id as id', 'supply.name', 'supply.category', 'supply.base_unit as unit',
                'supply.minimum', 'supply.ideal', 'balance.quantity',
            ])
            ->map(function (object $stock): array {
                $quantity = (string) ($stock->quantity ?? '0');
                $minimum = $stock->minimum === null ? null : (string) $stock->minimum;
                $ideal = $stock->ideal === null ? null : (string) $stock->ideal;
                $belowMinimum = $minimum !== null && BigDecimal::of($quantity)->isLessThan($minimum);
                $negative = BigDecimal::of($quantity)->isNegative();
                $target = $ideal ?? $minimum;

                return [
                    'id' => $stock->id,
                    'name' => $stock->name,
                    'category' => $stock->category,
                    'unit' => $stock->unit,
                    'quantity' => $quantity,
                    'minimum' => $minimum,
                    'ideal' => $ideal,
                    'below_minimum' => $belowMinimum,
                    'negative' => $negative,
                    'suggested_restock' => $belowMinimum && $target !== null
                        ? (string) BigDecimal::of($target)->minus($quantity)
                        : null,
                ];
            })
            ->values();

        $movementRows = DB::table('inventory_movements as movement')
            ->join('inventory_operations as operation', 'operation.id', '=', 'movement.operation_id')
            ->join('inventory_supplies as supply', 'supply.id', '=', 'movement.supply_id')
            ->where('movement.tenant_id', $tenant->id)
            ->where('movement.branch_id', $branch->id)
            ->where('operation.created_at', '>=', $startsAt)
            ->where('operation.created_at', '<', $endsAt)
            ->orderBy('operation.created_at')
            ->get([
                'operation.id as operation_id', 'operation.type', 'operation.created_at',
                'supply.id as supply_id', 'supply.public_id as id', 'supply.name', 'supply.base_unit as unit',
                'movement.quantity',
            ]);

        $activityByType = $movementRows
            ->groupBy('type')
            ->map(fn ($rows, string $type): array => [
                'type' => $type,
                'operations' => $rows->pluck('operation_id')->unique()->count(),
                'lines' => $rows->count(),
            ])
            ->sortByDesc('operations')
            ->values();

        $topConsumed = $movementRows
            ->filter(fn (object $movement) => BigDecimal::of((string) $movement->quantity)->isNegative())
            ->groupBy('supply_id')
            ->map(function ($rows): array {
                $first = $rows->first();
                $consumed = $rows->reduce(
                    fn (BigDecimal $total, object $movement) => $total->plus(BigDecimal::of((string) $movement->quantity)->abs()),
                    BigDecimal::zero(),
                );

                return [
                    'id' => $first->id,
                    'name' => $first->name,
                    'unit' => $first->unit,
                    'quantity' => (string) $consumed,
                ];
            })
            ->sortByDesc(fn (array $row) => (float) $row['quantity'])
            ->take(10)
            ->values();

        $orders = DB::table('orders')
            ->where('tenant_id', $tenant->id)
            ->where('branch_id', $branch->id)
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $startsAt)
            ->where('paid_at', '<', $endsAt)
            ->get(['id', 'total']);

        $salesTotal = round((float) $orders->sum('total'), 2);
        $salesCount = $orders->count();
        $topProducts = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.tenant_id', $tenant->id)
            ->where('orders.branch_id', $branch->id)
            ->where('orders.status', 'paid')
            ->whereNotNull('orders.paid_at')
            ->where('orders.paid_at', '>=', $startsAt)
            ->where('orders.paid_at', '<', $endsAt)
            ->selectRaw('order_items.product_name_snapshot as name, SUM(order_items.quantity) as units, SUM(order_items.line_total) as sales_total')
            ->groupBy('order_items.product_name_snapshot')
            ->orderByDesc('units')
            ->orderByDesc('sales_total')
            ->limit(5)
            ->get()
            ->map(fn (object $product): array => [
                'name' => $product->name,
                'units' => (int) $product->units,
                'sales_total' => round((float) $product->sales_total, 2),
            ])
            ->values();

        $countDays = $movementRows
            ->filter(fn (object $movement) => $movement->type === 'COUNT')
            ->map(fn (object $movement) => CarbonImmutable::parse($movement->created_at)->timezone($branch->timezone ?: $tenant->timezone ?: config('app.timezone'))->toDateString())
            ->unique()
            ->count();

        $shortages = $stocks->filter(fn (array $stock) => $stock['below_minimum'])->values();

        return [
            'generated_at' => now()->toIso8601String(),
            'branch' => ['id' => $branch->public_id, 'name' => $branch->name],
            'period' => [
                'date_from' => $startsAt->timezone($branch->timezone ?: $tenant->timezone ?: config('app.timezone'))->toDateString(),
                'date_to' => $endsAt->subSecond()->timezone($branch->timezone ?: $tenant->timezone ?: config('app.timezone'))->toDateString(),
                'timezone' => $branch->timezone ?: $tenant->timezone ?: config('app.timezone'),
            ],
            'summary' => [
                'supplies' => $stocks->count(),
                'below_minimum' => $shortages->count(),
                'negative_stock' => $stocks->where('negative', true)->count(),
                'sales_total' => $salesTotal,
                'sales_count' => $salesCount,
                'average_ticket' => $salesCount > 0 ? round($salesTotal / $salesCount, 2) : 0.0,
                'physical_count_days' => $countDays,
            ],
            'alerts' => array_values(array_filter([
                $shortages->isNotEmpty() ? $shortages->count().' insumo(s) están por debajo de su mínimo.' : null,
                $stocks->where('negative', true)->isNotEmpty() ? 'Hay existencias negativas que requieren revisión.' : null,
                $countDays < 2 ? 'Realiza un conteo físico al inicio y al cierre del período para analizar la variación real contra el consumo esperado.' : null,
            ])),
            'shortages' => $shortages,
            'stock_snapshot' => $stocks,
            'activity_by_type' => $activityByType,
            'top_consumed' => $topConsumed,
            'top_products' => $topProducts,
        ];
    }
}
