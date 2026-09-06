import {
    BoxIcon,
    MoreHorizontalIcon,
} from 'lucide-react'

import { Button } from '@/components/ui/button'
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table'

import { formatMoney } from '../utils/product-utils'

import type { Product } from '../types'

type ProductsTableProps = {
    products: Product[]
    deletingId: string | null
    onEdit: (product: Product) => void
    onDelete: (product: Product) => void
}

export function ProductsTable({
    products,
    deletingId,
    onEdit,
    onDelete,
}: ProductsTableProps) {
    return (
        <div className="overflow-hidden rounded-xl border bg-background">
            <div className="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-16">
                                Imagen
                            </TableHead>

                            <TableHead>
                                Producto
                            </TableHead>

                            <TableHead>
                                Categoría
                            </TableHead>

                            <TableHead>
                                Precio
                            </TableHead>

                            <TableHead>
                                Estado
                            </TableHead>

                            <TableHead className="w-16 text-right">
                                <span className="sr-only">
                                    Acciones
                                </span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>

                    <TableBody>
                        {products.map(
                            (product) => {
                                const deleting =
                                    deletingId ===
                                    product.id

                                return (
                                    <TableRow
                                        key={product.id}
                                    >
                                        <TableCell>
                                            <div className="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-muted">
                                                {product.image_path ? (
                                                    <img
                                                        src={
                                                            product.image_path
                                                        }
                                                        alt={
                                                            product.name
                                                        }
                                                        loading="lazy"
                                                        className="size-full object-cover"
                                                    />
                                                ) : (
                                                    <BoxIcon className="size-5 text-muted-foreground/50" />
                                                )}
                                            </div>
                                        </TableCell>

                                        <TableCell>
                                            <div className="max-w-xs">
                                                <p
                                                    className="truncate font-medium"
                                                    title={
                                                        product.name
                                                    }
                                                >
                                                    {product.name}
                                                </p>

                                                {product.description && (
                                                    <p
                                                        className="mt-0.5 truncate text-xs text-muted-foreground"
                                                        title={
                                                            product.description
                                                        }
                                                    >
                                                        {
                                                            product.description
                                                        }
                                                    </p>
                                                )}
                                            </div>
                                        </TableCell>

                                        <TableCell>
                                            <span className="text-sm text-muted-foreground">
                                                {
                                                    product.category
                                                        .name
                                                }
                                            </span>
                                        </TableCell>

                                        <TableCell>
                                            <span className="font-medium">
                                                {formatMoney(
                                                    product.price,
                                                )}
                                            </span>
                                        </TableCell>

                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <span
                                                    className={[
                                                        'size-2 rounded-full',
                                                        product.is_active
                                                            ? 'bg-emerald-500'
                                                            : 'bg-muted-foreground/30',
                                                    ].join(
                                                        ' ',
                                                    )}
                                                />

                                                <span className="text-sm text-muted-foreground">
                                                    {product.is_active
                                                        ? 'Activo'
                                                        : 'Inactivo'}
                                                </span>
                                            </div>
                                        </TableCell>

                                        <TableCell className="text-right">
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-8"
                                                        disabled={
                                                            deleting
                                                        }
                                                    >
                                                        <MoreHorizontalIcon className="size-4" />

                                                        <span className="sr-only">
                                                            Acciones de{' '}
                                                            {product.name}
                                                        </span>
                                                    </Button>
                                                </DropdownMenuTrigger>

                                                <DropdownMenuContent
                                                    align="end"
                                                    className="w-40"
                                                >
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            onEdit(
                                                                product,
                                                            )
                                                        }
                                                    >
                                                        Editar
                                                    </DropdownMenuItem>

                                                    <DropdownMenuSeparator />

                                                    <DropdownMenuItem
                                                        variant="destructive"
                                                        disabled={
                                                            deleting
                                                        }
                                                        onClick={() =>
                                                            onDelete(
                                                                product,
                                                            )
                                                        }
                                                    >
                                                        {deleting
                                                            ? 'Eliminando...'
                                                            : 'Eliminar'}
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </TableCell>
                                    </TableRow>
                                )
                            },
                        )}
                    </TableBody>
                </Table>
            </div>
        </div>
    )
}
