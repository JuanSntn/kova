import {
    ShoppingCartIcon,
    Trash2Icon,
} from 'lucide-react'

import { Button } from '@/components/ui/button'
import {
    Card,
    CardContent,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card'

import {
    PosCartItem,
} from './pos-cart-item'
import {
    PosEmptyCart,
} from './pos-empty-cart'
import {
    PosPaymentMethod,
} from './pos-payment-method'
import {
    PosSummary,
} from './pos-summary'

import type {
    PosCartItem as CartItem,
    PosCartTotals,
    PosPaymentMethod as PaymentMethod,
} from '../types'

type PosCartProps = {
    items: CartItem[]

    itemCount: number

    totals: PosCartTotals

    paymentMethod: PaymentMethod

    onPaymentMethodChange: (
        method: PaymentMethod
    ) => void

    onIncrease: (
        lineId: string
    ) => void

    onDecrease: (
        lineId: string
    ) => void

    onRemove: (
        lineId: string
    ) => void

    onClear: () => void

    checkoutDisabled?: boolean
    onCheckout: () => void
}

export function PosCart({
    items,
    itemCount,
    totals,
    paymentMethod,
    onPaymentMethodChange,
    onIncrease,
    onDecrease,
    onRemove,
    onClear,
    onCheckout,
    checkoutDisabled = false,
}: PosCartProps) {
    const hasItems =
        items.length > 0

    return (
        <Card
            className="
                flex
                overflow-hidden
                py-0

                xl:sticky
                xl:top-3
                xl:h-[calc(100svh-8rem)]
                xl:max-h-[calc(100svh-8rem)]
            "
        >
            {/*
            |--------------------------------------------------------------------------
            | Header
            |--------------------------------------------------------------------------
            |
            | Siempre visible.
            |
            */}

            <CardHeader className="shrink-0 border-b px-3 py-3">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <CardTitle className="flex items-center gap-2 text-sm">
                            <ShoppingCartIcon className="size-4" />

                            Venta actual
                        </CardTitle>

                        <p className="mt-0.5 text-[11px] text-muted-foreground">
                            Mostrador ·{' '}
                            {itemCount}{' '}
                            {itemCount === 1
                                ? 'artículo'
                                : 'artículos'}
                        </p>
                    </div>

                    {hasItems && (
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            className="
                                h-7
                                px-2
                                text-[11px]
                                text-muted-foreground
                                hover:text-destructive
                            "
                            onClick={
                                onClear
                            }
                        >
                            <Trash2Icon className="size-3.5" />

                            Vaciar
                        </Button>
                    )}
                </div>
            </CardHeader>

            {/*
            |--------------------------------------------------------------------------
            | Items
            |--------------------------------------------------------------------------
            |
            | Esta es la ÚNICA sección
            | que hace scroll.
            |
            | min-h-0 es importante cuando
            | usamos flex + overflow.
            |
            */}

            <CardContent
                className="
                    min-h-0
                    flex-1
                    overflow-hidden
                    p-0
                "
            >
                {!hasItems ? (
                    <PosEmptyCart />
                ) : (
                    <div
                        className="
                            h-full
                            space-y-1.5
                            overflow-y-auto
                            overscroll-contain
                            p-2.5
                            pr-2
                        "
                    >
                        {items.map(
                            (item) => (
                                <PosCartItem
                                    key={
                                        item.line_id
                                    }
                                    item={
                                        item
                                    }
                                    onIncrease={
                                        onIncrease
                                    }
                                    onDecrease={
                                        onDecrease
                                    }
                                    onRemove={
                                        onRemove
                                    }
                                />
                            ),
                        )}
                    </div>
                )}
            </CardContent>

            {/*
            |--------------------------------------------------------------------------
            | Footer
            |--------------------------------------------------------------------------
            |
            | Nunca hace scroll.
            |
            | Siempre tenemos visibles:
            |
            | - método de pago
            | - subtotal
            | - total
            | - botón cobrar
            |
            */}

            <CardFooter
                className="
                    block
                    shrink-0
                    space-y-3
                    border-t
                    bg-background
                    px-3
                    py-3
                "
            >
                <PosPaymentMethod
                    value={
                        paymentMethod
                    }
                    onChange={
                        onPaymentMethodChange
                    }
                />

                <PosSummary
                    totals={
                        totals
                    }
                />

                <Button
                    type="button"
                    size="lg"
                    className="h-10 w-full"
                    disabled={
                        !hasItems || checkoutDisabled
                    }
                    onClick={
                        onCheckout
                    }
                >
                    Cobrar{' '}

                    {hasItems &&
                        formatMoney(
                            totals.total,
                        )}
                </Button>
            </CardFooter>
        </Card>
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
