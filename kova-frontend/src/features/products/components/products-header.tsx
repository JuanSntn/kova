import { PlusIcon } from 'lucide-react'

import { Button } from '@/components/ui/button'

type ProductsHeaderProps = {
    canCreate: boolean
    onCreate: () => void
}

export function ProductsHeader({
    canCreate,
    onCreate,
}: ProductsHeaderProps) {
    return (
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 className="text-xl font-semibold tracking-tight">
                    Productos
                </h1>

                <p className="mt-1 text-sm text-muted-foreground">
                    Administra los productos disponibles en tu punto de venta.
                </p>
            </div>

            <Button
                size="sm"
                disabled={!canCreate}
                onClick={onCreate}
            >
                <PlusIcon className="size-4" />
                Producto
            </Button>
        </div>
    )
}