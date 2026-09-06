import {
    CreditCardIcon,
    ReceiptTextIcon,
    ShoppingBagIcon,
    TrophyIcon,
} from 'lucide-react'

import {
    Card,
    CardContent,
} from '@/components/ui/card'

import type {
    SalesSummary,
} from '../types'

import {
    formatMoney,
} from '../utils'

export function SalesSummaryCards({
    summary,
}: {
    summary: SalesSummary
}) {
    const {
        metrics,
        top_products,
    } = summary

    const topProduct =
        top_products[0]

    return (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Card>
                <CardContent className="p-4">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <p className="text-xs text-muted-foreground">
                                Ventas del período
                            </p>

                            <p className="mt-1 text-2xl font-semibold tracking-tight">
                                {formatMoney(
                                    metrics.total_sales,
                                )}
                            </p>
                        </div>

                        <ShoppingBagIcon className="size-5 text-muted-foreground" />
                    </div>

                    {metrics.sales_limit !== null && (
                        <div className="mt-4">
                            <div className="mb-1.5 flex justify-between text-xs text-muted-foreground">
                                <span>
                                    {metrics.sales_limit_percentage?.toFixed(
                                        1,
                                    )}
                                    % utilizado
                                </span>

                                <span>
                                    {formatMoney(
                                        metrics.sales_limit,
                                    )}
                                </span>
                            </div>

                            <div className="h-2 overflow-hidden rounded-full bg-muted">
                                <div
                                    className="h-full rounded-full bg-primary transition-all"
                                    style={{
                                        width: `${Math.min(
                                            100,
                                            metrics.sales_limit_percentage ??
                                            0,
                                        )}%`,
                                    }}
                                />
                            </div>

                            <p className="mt-2 text-xs text-muted-foreground">
                                Disponible:{' '}
                                {formatMoney(
                                    metrics.sales_limit_remaining ??
                                    0,
                                )}
                            </p>
                        </div>
                    )}
                </CardContent>
            </Card>

            <MetricCard
                title="Transacciones"
                value={`${metrics.transaction_count}`}
                subtitle="Ventas cobradas"
                icon={
                    <ReceiptTextIcon className="size-5" />
                }
            />

            <MetricCard
                title="Ticket promedio"
                value={formatMoney(
                    metrics.average_ticket,
                )}
                subtitle="Promedio por venta"
                icon={
                    <CreditCardIcon className="size-5" />
                }
            />

            <MetricCard
                title="Producto más vendido"
                value={
                    topProduct?.name ??
                    'Sin ventas'
                }
                subtitle={
                    topProduct
                        ? `${topProduct.units} unidades`
                        : 'Aún no hay información'
                }
                icon={
                    <TrophyIcon className="size-5" />
                }
            />
        </div>
    )
}

function MetricCard({
    title,
    value,
    subtitle,
    icon,
}: {
    title: string
    value: string
    subtitle: string
    icon: React.ReactNode
}) {
    return (
        <Card>
            <CardContent className="p-4">
                <div className="flex items-start justify-between gap-4">
                    <div className="min-w-0">
                        <p className="text-xs text-muted-foreground">
                            {title}
                        </p>

                        <p className="mt-1 truncate text-xl font-semibold tracking-tight">
                            {value}
                        </p>

                        <p className="mt-1 text-xs text-muted-foreground">
                            {subtitle}
                        </p>
                    </div>

                    <div className="text-muted-foreground">
                        {icon}
                    </div>
                </div>
            </CardContent>
        </Card>
    )
}