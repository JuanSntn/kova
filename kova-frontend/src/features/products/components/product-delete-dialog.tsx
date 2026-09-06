import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog'

import type { Product } from '../types'

type ProductDeleteDialogProps = {
    product: Product | null
    deleting: boolean
    onOpenChange: (open: boolean) => void
    onConfirm: () => void
}

export function ProductDeleteDialog({
    product,
    deleting,
    onOpenChange,
    onConfirm,
}: ProductDeleteDialogProps) {
    return (
        <AlertDialog
            open={product !== null}
            onOpenChange={onOpenChange}
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        ¿Eliminar producto?
                    </AlertDialogTitle>

                    <AlertDialogDescription>
                        {product
                            ? `El producto "${product.name}" dejará de estar disponible.`
                            : 'El producto será eliminado.'}
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <AlertDialogFooter>
                    <AlertDialogCancel
                        disabled={deleting}
                    >
                        Cancelar
                    </AlertDialogCancel>

                    <AlertDialogAction
                        disabled={deleting}
                        onClick={(event) => {
                            event.preventDefault()
                            onConfirm()
                        }}
                    >
                        {deleting
                            ? 'Eliminando...'
                            : 'Eliminar producto'}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    )
}