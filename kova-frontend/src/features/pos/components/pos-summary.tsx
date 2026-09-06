import type {
    PosCartTotals,
} from '../types'

type PosSummaryProps = {
    totals:
    PosCartTotals
}

export function PosSummary({
    totals,
}: PosSummaryProps) {
    return (
        <div className="space-y-1.5 text-xs">
            <div className="flex justify-between gap-4 text-muted-foreground">
                <span>
                    Subtotal
                </span>

                <span>
                    {formatMoney(
                        totals.subtotal,
                    )}
                </span>
            </div>

            <div className="border-t pt-2">
                <div className="flex items-end justify-between gap-4">
                    <span className="font-medium">
                        Total
                    </span>

                    <span className="text-lg font-bold tracking-tight">
                        {formatMoney(
                            totals.total,
                        )}
                    </span>
                </div>
            </div>
        </div>
    )
}

function formatMoney(
    value: number,
): string {
    return new Intl.NumberFormat(
        'es-MX',
        {
            style:
                'currency',

            currency:
                'MXN',

            minimumFractionDigits:
                2,

            maximumFractionDigits:
                2,
        },
    ).format(value)
}