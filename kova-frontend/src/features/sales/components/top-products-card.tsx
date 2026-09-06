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

export function TopProductsCard({
    products,
}: {
    products: SalesSummary['top_products']
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm">
                    Productos más vendidos
                </CardTitle>
            </CardHeader>

            <CardContent>
                {products.length === 0 ? (
                    <p className="py-8 text-center text-sm text-muted-foreground">
                        Aún no hay productos vendidos.
                    </p>
                ) : (
                    <div className="space-y-4">
                        {products.map(
                            (
                                product,
                                index,
                            ) => (
                                <div
                                    key={
                                        product.name
                                    }
                                    className="flex items-center gap-3"
                                >
                                    <div className="flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium">
                                        {index +
                                            1}
                                    </div>

                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium">
                                            {
                                                product.name
                                            }
                                        </p>

                                        <p className="text-xs text-muted-foreground">
                                            {
                                                product.units
                                            }{' '}
                                            unidades
                                        </p>
                                    </div>

                                    <p className="shrink-0 text-sm font-medium">
                                        {formatMoney(
                                            product.sales_total,
                                        )}
                                    </p>
                                </div>
                            ),
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    )
}