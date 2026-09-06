import {
    BoxIcon,
    PlusIcon,
} from 'lucide-react'

import { Button } from '@/components/ui/button'
import {
    Card,
    CardContent,
} from '@/components/ui/card'

import type {
    PosProduct,
} from '../types'

type PosProductCardProps = {
    product:
    PosProduct

    onAdd: (
        product: PosProduct
    ) => void
}

export function PosProductCard({
    product,
    onAdd,
}: PosProductCardProps) {
    return (
        <Card
            className="group cursor-pointer overflow-hidden p-0 transition-all hover:-translate-y-0.5 hover:shadow-sm"
            onClick={() =>
                onAdd(
                    product,
                )
            }
        >
            <div className="relative aspect-[16/10] overflow-hidden bg-muted">
                {product.image_path ? (
                    <img
                        src={
                            product.image_path
                        }
                        alt={
                            product.name
                        }
                        loading="lazy"
                        className="size-full object-cover transition-transform duration-300 group-hover:scale-[1.03]"
                    />
                ) : (
                    <div className="flex size-full items-center justify-center">
                        <BoxIcon className="size-6 text-muted-foreground/40" />
                    </div>
                )}

                <Button
                    type="button"
                    size="icon"
                    className="absolute bottom-1.5 right-1.5 size-7 rounded-full shadow-sm"
                    aria-label={
                        `Agregar ${product.name}`
                    }
                    onClick={(
                        event,
                    ) => {
                        event.stopPropagation()

                        onAdd(
                            product,
                        )
                    }}
                >
                    <PlusIcon className="size-3.5" />
                </Button>
            </div>

            <CardContent className="space-y-1 p-2.5">
                <p
                    className="truncate text-xs font-medium"
                    title={
                        product.name
                    }
                >
                    {product.name}
                </p>

                <div className="flex items-center justify-between gap-2">
                    <p className="truncate text-[10px] text-muted-foreground">
                        {
                            product.category
                                .name
                        }
                    </p>

                    <p className="shrink-0 text-xs font-semibold text-primary">
                        {formatMoney(
                            product.price,
                        )}
                    </p>
                </div>
            </CardContent>
        </Card>
    )
}

function formatMoney(
    value:
        | string
        | number,
): string {
    const amount =
        Number(value)

    if (
        !Number.isFinite(
            amount,
        )
    ) {
        return '$0.00'
    }

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
        amount,
    )
}