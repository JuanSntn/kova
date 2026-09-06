import {
    BoxIcon,
    PlusIcon,
} from 'lucide-react'

import { Button } from '@/components/ui/button'

type EmptyProductsProps = {
    hasProducts: boolean
    hasSearch: boolean
    categorySelected: boolean
    canCreate: boolean
    onCreate: () => void
}

export function EmptyProducts({
    hasProducts,
    hasSearch,
    categorySelected,
    canCreate,
    onCreate,
}: EmptyProductsProps) {
    let message =
        'Todavía no tienes productos registrados.'

    if (
        hasProducts &&
        (
            hasSearch ||
            categorySelected
        )
    ) {
        message =
            'No encontramos productos con estos filtros.'
    }

    return (
        <div className="flex min-h-52 flex-col items-center justify-center rounded-xl border border-dashed bg-muted/10 p-8 text-center">
            <div className="flex size-11 items-center justify-center rounded-xl bg-muted">
                <BoxIcon className="size-5 text-muted-foreground" />
            </div>

            <h3 className="mt-4 text-sm font-medium">
                Sin productos
            </h3>

            <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                {message}
            </p>

            {!hasProducts &&
                canCreate && (
                    <Button
                        size="sm"
                        className="mt-5"
                        onClick={onCreate}
                    >
                        <PlusIcon className="size-4" />
                        Crear producto
                    </Button>
                )}
        </div>
    )
}