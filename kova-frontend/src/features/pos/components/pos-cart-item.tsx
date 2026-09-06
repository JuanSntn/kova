import {
    MinusIcon,
    PlusIcon,
    Trash2Icon,
} from 'lucide-react'

import { Button } from '@/components/ui/button'

import type {
    PosCartItem as CartItem,
} from '../types'

type PosCartItemProps = {
    item:
    CartItem

    onIncrease: (
        lineId: string
    ) => void

    onDecrease: (
        lineId: string
    ) => void

    onRemove: (
        lineId: string
    ) => void
}

export function PosCartItem({
    item,
    onIncrease,
    onDecrease,
    onRemove,
}: PosCartItemProps) {
    const lineTotal =
        item.unit_price *
        item.quantity

    return (
        <div className="rounded-lg border bg-background px-3 py-2">
            <div className="flex items-center gap-2">
                <div className="min-w-0 flex-1">
                    <p
                        className="truncate text-xs font-medium"
                        title={
                            item.name_snapshot
                        }
                    >
                        {
                            item.name_snapshot
                        }
                    </p>

                    <p className="text-[10px] text-muted-foreground">
                        {formatMoney(
                            item.unit_price,
                        )}{' '}
                        c/u
                    </p>
                </div>

                <p className="shrink-0 text-xs font-semibold">
                    {formatMoney(
                        lineTotal,
                    )}
                </p>

                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    className="size-7 shrink-0 text-muted-foreground hover:text-destructive"
                    onClick={() =>
                        onRemove(
                            item.line_id,
                        )
                    }
                >
                    <Trash2Icon className="size-3.5" />
                </Button>
            </div>

            <div className="mt-1.5 flex items-center">
                <div className="flex items-center rounded-md border">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-6"
                        onClick={() =>
                            onDecrease(
                                item.line_id,
                            )
                        }
                    >
                        <MinusIcon className="size-3" />
                    </Button>

                    <div className="min-w-7 text-center text-xs font-medium">
                        {
                            item.quantity
                        }
                    </div>

                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-6"
                        onClick={() =>
                            onIncrease(
                                item.line_id,
                            )
                        }
                    >
                        <PlusIcon className="size-3" />
                    </Button>
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