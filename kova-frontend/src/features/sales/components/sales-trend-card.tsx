import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card'

import type {
    SalesSummary,
} from '../types'

import {
    formatMoney,
} from '../utils'

export function SalesTrendCard({
    data,
}: {
    data: SalesSummary['sales_by_day']
}) {
    const max = Math.max(
        ...data.map(
            (item) => item.total,
        ),
        1,
    )

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm">
                    Ventas por día
                </CardTitle>
            </CardHeader>

            <CardContent>
                {data.length === 0 ? (
                    <p className="py-12 text-center text-sm text-muted-foreground">
                        Aún no hay ventas en este período.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <div className="flex min-w-max items-end gap-2">
                            {data.map(
                                (item) => {
                                    const height =
                                        item.total > 0
                                            ? Math.max(
                                                8,
                                                (
                                                    item.total /
                                                    max
                                                ) *
                                                150,
                                            )
                                            : 2

                                    return (
                                        <div
                                            key={
                                                item.date
                                            }
                                            className="group flex w-9 flex-col items-center gap-2"
                                            title={`${item.date}: ${formatMoney(
                                                item.total,
                                            )}`}
                                        >
                                            <div className="flex h-40 items-end">
                                                <div
                                                    className="w-5 rounded-t bg-primary/80 transition hover:bg-primary"
                                                    style={{
                                                        height,
                                                    }}
                                                />
                                            </div>

                                            <span className="text-[10px] text-muted-foreground">
                                                {Number(
                                                    item.date.slice(
                                                        -2,
                                                    ),
                                                )}
                                            </span>
                                        </div>
                                    )
                                },
                            )}
                        </div>
                    </div>
                )}
            </CardContent>
        </Card>
    )
}