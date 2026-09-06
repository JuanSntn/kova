import {
    ShoppingCartIcon,
} from 'lucide-react'

export function PosEmptyCart() {
    return (
        <div className="flex min-h-56 flex-col items-center justify-center px-6 py-8 text-center">
            <div className="flex size-12 items-center justify-center rounded-xl bg-muted">
                <ShoppingCartIcon className="size-5 text-muted-foreground" />
            </div>

            <h3 className="mt-4 text-sm font-medium">
                Tu venta está vacía
            </h3>

            <p className="mt-1 max-w-xs text-sm text-muted-foreground">
                Selecciona un producto
                del catálogo para comenzar
                una venta.
            </p>
        </div>
    )
}