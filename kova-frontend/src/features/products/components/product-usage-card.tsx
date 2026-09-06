import { Badge } from '@/components/ui/badge'
import {
    Card,
    CardContent,
} from '@/components/ui/card'

import type {
    ProductPlan,
    ProductUsage,
} from '../types'

type ProductUsageCardProps = {
    plan: ProductPlan
    usage: ProductUsage
}

export function ProductUsageCard({
    plan,
    usage,
}: ProductUsageCardProps) {
    const percentage =
        usage.limit &&
            usage.limit > 0
            ? Math.min(
                (
                    usage.used /
                    usage.limit
                ) * 100,
                100,
            )
            : 0

    return (
        <Card>
            <CardContent className="p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2">
                            <p className="text-sm font-medium">
                                Uso de productos
                            </p>

                            <Badge
                                variant="secondary"
                                className="text-xs"
                            >
                                Plan {plan.name}
                            </Badge>
                        </div>

                        {usage.is_unlimited ? (
                            <p className="mt-1 text-xs text-muted-foreground">
                                {usage.used}{' '}
                                productos registrados.
                                Tu plan no tiene límite.
                            </p>
                        ) : (
                            <p className="mt-1 text-xs text-muted-foreground">
                                {usage.used} de{' '}
                                {usage.limit}{' '}
                                productos utilizados
                            </p>
                        )}
                    </div>

                    {!usage.is_unlimited &&
                        usage.remaining !==
                        null && (
                            <div className="sm:text-right">
                                <p className="text-xl font-semibold">
                                    {usage.remaining}
                                </p>

                                <p className="text-xs text-muted-foreground">
                                    disponibles
                                </p>
                            </div>
                        )}
                </div>

                {!usage.is_unlimited &&
                    usage.limit !==
                    null && (
                        <div className="mt-3">
                            <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                                <div
                                    className="h-full rounded-full bg-primary transition-all"
                                    style={{
                                        width:
                                            `${percentage}%`,
                                    }}
                                />
                            </div>
                        </div>
                    )}
            </CardContent>
        </Card>
    )
}
