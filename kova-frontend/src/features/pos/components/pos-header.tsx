import {
    PlusIcon,
    ShoppingBagIcon,
} from 'lucide-react'

import {
    Button,
} from '@/components/ui/button'

type PosHeaderProps = {
    itemCount: number

    onNewSale: () => void
}

export function PosHeader({
    itemCount,
    onNewSale,
}: PosHeaderProps) {
    return (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div className="flex items-center gap-3">
                <div className="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <ShoppingBagIcon className="size-4" />
                </div>

                <div>
                    <h1 className="text-xl font-semibold tracking-tight">
                        Punto de venta
                    </h1>

                    <p className="text-sm text-muted-foreground">
                        Venta de mostrador
                    </p>
                </div>
            </div>

            <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={
                    onNewSale
                }
            >
                <PlusIcon className="size-4" />

                Nueva venta

                {itemCount > 0 && (
                    <span className="ml-1 rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium">
                        {itemCount}
                    </span>
                )}
            </Button>
        </div>
    )
}