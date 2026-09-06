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
    paymentMethodLabel,
} from '../utils'

export function PaymentMethodsCard({
    methods,
}: {
    methods: SalesSummary['payment_methods']
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm">
                    Métodos de pago
                </CardTitle>
            </CardHeader>

            <CardContent className="space-y-5">
                {methods.map(
                    (method) => (
                        <div
                            key={
                                method.method
                            }
                        >
                            <div className="mb-2 flex items-center justify-between gap-4">
                                <div>
                                    <p className="text-sm font-medium">
                                        {paymentMethodLabel(
                                            method.method,
                                        )}
                                    </p>

                                    <p className="text-xs text-muted-foreground">
                                        {
                                            method.transactions
                                        }{' '}
                                        ventas
                                    </p>
                                </div>

                                <span className="text-sm font-semibold">
                                    {method.percentage.toFixed(
                                        1,
                                    )}
                                    %
                                </span>
                            </div>

                            <div className="h-2 overflow-hidden rounded-full bg-muted">
                                <div
                                    className="h-full rounded-full bg-primary"
                                    style={{
                                        width: `${method.percentage}%`,
                                    }}
                                />
                            </div>
                        </div>
                    ),
                )}
            </CardContent>
        </Card>
    )
}