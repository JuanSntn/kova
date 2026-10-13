<?php

namespace App\Services\Sales;

use App\Models\Tenant;
use App\Models\Branch;
use App\Services\Plans\TenantPlanService;
use Illuminate\Support\Facades\DB;

final class SalesReportService
{
    public function __construct(
        private readonly TenantPlanService $plans,
        private readonly SalesPeriodService $periods,
    ) {}

    public function summary(
        Tenant $tenant,
        Branch $branch,
    ): array {
        $plan = $this->plans->current(
            $tenant
        );

        $planCode = (string) $plan->code;

        $period = $this->periods->current(
            tenant: $tenant,
            plan: $plan,
        );

        $timezone = $branch->timezone ?: $tenant->timezone
            ?: config(
                'app.timezone',
                'UTC'
            );

        /*
         * Ventas del período.
         */
        $orders = $tenant
            ->orders()
            ->where('branch_id', $branch->id)
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->where(
                'paid_at',
                '>=',
                $period['starts_at'],
            )
            ->where(
                'paid_at',
                '<',
                $period['ends_at'],
            )
            ->get([
                'id',
                'total',
                'payment_method',
                'paid_at',
            ]);

        $totalSales = round(
            (float) $orders->sum('total'),
            2,
        );

        $transactionCount =
            $orders->count();

        $averageTicket =
            $transactionCount > 0
            ? round(
                $totalSales
                    / $transactionCount,
                2,
            )
            : 0.0;

        /*
         * Top productos.
         *
         * Usamos snapshots históricos,
         * no el nombre/precio actual.
         */
        $topProducts = DB::table(
            'order_items'
        )
            ->join(
                'orders',
                'orders.id',
                '=',
                'order_items.order_id',
            )
            ->where(
                'orders.tenant_id',
                $tenant->id,
            )
            ->where('orders.branch_id', $branch->id)
            ->where(
                'orders.status',
                'paid',
            )
            ->whereNotNull(
                'orders.paid_at',
            )
            ->where(
                'orders.paid_at',
                '>=',
                $period['starts_at'],
            )
            ->where(
                'orders.paid_at',
                '<',
                $period['ends_at'],
            )
            ->selectRaw(
                '
                order_items.product_name_snapshot as name,
                SUM(order_items.quantity) as units,
                SUM(order_items.line_total) as sales_total
                '
            )
            ->groupBy(
                'order_items.product_name_snapshot'
            )
            ->orderByDesc('units')
            ->orderByDesc('sales_total')
            ->limit(5)
            ->get()
            ->map(
                fn ($item) => [
                    'name' => $item->name,

                    'units' => (int) $item->units,

                    'sales_total' => round(
                        (float) $item->sales_total,
                        2,
                    ),
                ]
            )
            ->values();

        /*
         * Métodos de pago.
         */
        $paymentMethods =
            collect([
                'cash',
                'card',
                'transfer',
            ])
                ->map(
                    function (
                        string $method
                    ) use (
                        $orders,
                        $transactionCount
                    ): array {
                        $methodOrders =
                            $orders->where(
                                'payment_method',
                                $method,
                            );

                        $transactions =
                            $methodOrders->count();

                        return [
                            'method' => $method,

                            'transactions' => $transactions,

                            'percentage' => $transactionCount > 0
                                ? round(
                                    (
                                        $transactions
                                        / $transactionCount
                                    ) * 100,
                                    2,
                                )
                                : 0,

                            'sales_total' => round(
                                (float) $methodOrders
                                    ->sum('total'),
                                2,
                            ),
                        ];
                    }
                )
                ->sortByDesc(
                    'transactions'
                )
                ->values();

        /*
         * Ventas por día.
         *
         * Lo agrupamos en PHP porque así
         * funciona igual en SQLite/MySQL
         * y respetamos correctamente el
         * timezone del tenant.
         */
        $salesByDay = [];

        $cursor = $period['starts_at']
            ->copy()
            ->timezone($timezone)
            ->startOfDay();

        $periodEndLocal =
            $period['ends_at']
                ->copy()
                ->timezone($timezone);

        $today = now($timezone);

        $lastDay =
            $today->lessThan(
                $periodEndLocal
            )
            ? $today->copy()->endOfDay()
            : $periodEndLocal
                ->copy()
                ->subSecond();

        while (
            $cursor->lessThanOrEqualTo(
                $lastDay
            )
        ) {
            $date =
                $cursor->format('Y-m-d');

            $salesByDay[$date] = [
                'date' => $date,
                'total' => 0.0,
                'transactions' => 0,
            ];

            $cursor->addDay();
        }

        foreach ($orders as $order) {
            $date = $order
                ->paid_at
                ->copy()
                ->timezone($timezone)
                ->format('Y-m-d');

            if (
                ! isset(
                    $salesByDay[$date]
                )
            ) {
                continue;
            }

            $salesByDay[$date]['total'] =
                round(
                    $salesByDay[$date]['total']
                        + (float) $order->total,
                    2,
                );

            $salesByDay[$date]['transactions']++;
        }

        /*
         * Límite comercial.
         */
        $salesLimit =
            $plan->monthly_sales_limit !== null
            ? (float) $plan->monthly_sales_limit
            : null;

        $remaining =
            $salesLimit !== null
            ? max(
                0,
                round(
                    $salesLimit
                        - $totalSales,
                    2,
                )
            )
            : null;

        $percentage =
            $salesLimit !== null
            && $salesLimit > 0
            ? min(
                100,
                round(
                    (
                        $totalSales
                        / $salesLimit
                    ) * 100,
                    2,
                )
            )
            : null;

        return [
            'timezone' => $timezone,

            'plan' => [
                'code' => $planCode,

                'name' => $plan->name,
            ],

            'period' => [
                'type' => $period['type'],

                'starts_at' => $period['starts_at']
                    ->toIso8601String(),

                'ends_at' => $period['ends_at']
                    ->toIso8601String(),
            ],

            'metrics' => [
                'total_sales' => $totalSales,

                'transaction_count' => $transactionCount,

                'average_ticket' => $averageTicket,

                'sales_limit' => $salesLimit,

                'sales_limit_used' => $totalSales,

                'sales_limit_remaining' => $remaining,

                'sales_limit_percentage' => $percentage,
            ],

            'top_products' => $topProducts,

            'payment_methods' => $paymentMethods,

            'sales_by_day' => array_values(
                $salesByDay
            ),
        ];
    }
}
