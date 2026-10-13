import {
    BoxIcon,
    MoreHorizontalIcon,
} from 'lucide-react'

import { Badge } from '@/components/ui/badge'
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
        <div className="overflow-hidden rounded-xl border bg-card">
            <div>
                <Table>
                    <TableHeader>
                        <TableRow className="bg-muted/40 hover:bg-muted/40">
                            <TableHead className="pl-5">
                                Producto
                            </TableHead>

                            <TableHead>
                                Categoría
                            </TableHead>

                            <TableHead className="w-[140px] text-right">
                                Precio
                            </TableHead>

                            <TableHead className="w-[140px]">
                                Estado
                            </TableHead>

                            <TableHead className="w-[70px] text-right">
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

                                const locked =
                                    product.locked_by_plan

                                return (
                                    <TableRow
                                        key={product.id}
                                        className={locked ? 'group bg-muted/20' : 'group'}
                                    >
                                        <TableCell className="py-4 pl-5">
                                            <div className="flex items-center gap-3">
                                            <div className="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted">
                                                {product.image_path ? (
                                                    <img
                                                        src={
                                                            product.image_path
                                                        }
                                                        alt=""
                                                        loading="lazy"
                                                        className="size-full object-cover"
                                                    />
                                                ) : (
                                                    <BoxIcon className="size-5 text-muted-foreground/50" />
                                                )}
                                            </div>
                                            <div className="min-w-0 max-w-xs">
                                                <p
                                                    className="truncate font-medium"
                                                    title={
                                                        product.name
                                                    }
                                                >
                                                    {product.name}
                                                </p>

                                                {locked && (
                                                    <Badge
                                                        variant="outline"
                                                        className="mt-1 h-5 px-1.5 text-[10px] font-normal text-muted-foreground"
                                                    >
                                                        Bloqueado por plan
                                                    </Badge>
                                                )}

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

                                        <TableCell className="pr-4 text-right">
                                            <span className="font-medium tabular-nums">
                                                {formatMoney(
                                                    product.price,
                                                )}
                                            </span>
                                        </TableCell>

                                        <TableCell>
                                            <Badge variant={product.is_active ? 'secondary' : 'outline'} className="font-normal">
                                                <span className={[
                                                    'mr-1.5 size-1.5 rounded-full',
                                                    locked ? 'bg-amber-500' : product.is_active ? 'bg-emerald-500' : 'bg-muted-foreground/50',
                                                ].join(' ')} />
                                                {locked ? 'Bloqueado' : product.is_active ? 'Activo' : 'Inactivo'}
                                            </Badge>
                                        </TableCell>

                                        <TableCell className="pr-4 text-right">
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-8"
                                                        disabled={
                                                            deleting || locked
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
                                                        disabled={locked}
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
                                                            deleting || locked
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
