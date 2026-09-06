import {
    BanknoteIcon,
} from 'lucide-react'

import {
    Button,
} from '@/components/ui/button'
import {
    Input,
} from '@/components/ui/input'
import {
    Label,
} from '@/components/ui/label'

type CashPaymentProps = {
    total:
    number

    amountReceived:
    string

    onAmountReceivedChange: (
        value: string
    ) => void
}

export function CashPayment({
    total,
    amountReceived,
    onAmountReceivedChange,
}: CashPaymentProps) {
    const received =
        Number(
            amountReceived,
        ) || 0

    const change =
        Math.max(
            received -
            total,
            0,
        )

    const missing =
        Math.max(
            total -
            received,
            0,
        )

    const suggestions =
        getCashSuggestions(
            total,
        )

    return (
        <div className="space-y-4">
            <div className="space-y-1.5">
                <Label
                    htmlFor="cash-received"
                >
                    Efectivo recibido
                </Label>

                <div className="relative">
                    <BanknoteIcon className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />

                    <Input
                        id="cash-received"
                        type="number"
                        min="0"
                        step="0.01"
                        inputMode="decimal"
                        value={
                            amountReceived
                        }
                        className="h-11 pl-9 text-lg font-medium"
                        placeholder="0.00"
                        onChange={(
                            event,
                        ) =>
                            onAmountReceivedChange(
                                event
                                    .target
                                    .value,
                            )
                        }
                    />
                </div>
            </div>

            {/*
            |--------------------------------------------------------------------------
            | Quick amounts
            |--------------------------------------------------------------------------
            */}

            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    className="h-8"
                    onClick={() =>
                        onAmountReceivedChange(
                            total.toFixed(
                                2,
                            ),
                        )
                    }
                >
                    Pago exacto
                </Button>

                {suggestions.map(
                    (amount) => (
                        <Button
                            key={
                                amount
                            }
                            type="button"
                            size="sm"
                            variant="outline"
                            className="h-8"
                            onClick={() =>
                                onAmountReceivedChange(
                                    amount.toFixed(
                                        2,
                                    ),
                                )
                            }
                        >
                            {formatMoney(
                                amount,
                            )}
                        </Button>
                    ),
                )}
            </div>

            {/*
            |--------------------------------------------------------------------------
            | Change
            |--------------------------------------------------------------------------
            */}

            <div className="rounded-lg bg-muted/40 px-4 py-3">
                {received >=
                    total ? (
                    <div className="flex items-center justify-between gap-4">
                        <span className="text-sm text-muted-foreground">
                            Cambio
                        </span>

                        <span className="text-xl font-semibold">
                            {formatMoney(
                                change,
                            )}
                        </span>
                    </div>
                ) : (
                    <div className="flex items-center justify-between gap-4">
                        <span className="text-sm text-muted-foreground">
                            Faltan
                        </span>

                        <span className="text-sm font-medium text-destructive">
                            {formatMoney(
                                missing,
                            )}
                        </span>
                    </div>
                )}
            </div>
        </div>
    )
}

function getCashSuggestions(
    total: number,
): number[] {
    const amounts =
        [
            Math.ceil(
                total / 50,
            ) * 50,

            Math.ceil(
                total / 100,
            ) * 100,

            500,
            1000,
        ]

    return [
        ...new Set(
            amounts.filter(
                (amount) =>
                    amount >
                    total,
            ),
        ),
    ]
        .sort(
            (a, b) =>
                a - b,
        )
        .slice(
            0,
            3,
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
    ).format(
        value,
    )
}