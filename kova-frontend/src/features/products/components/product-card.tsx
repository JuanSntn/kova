import {
    BoxIcon,
    MoreHorizontalIcon,
} from 'lucide-react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
    Card,
    CardContent,
} from '@/components/ui/card'
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'

import { formatMoney } from '../utils/product-utils'

import type { Product } from '../types'

type ProductCardProps = {
    product: Product
    deleting: boolean
    onEdit: () => void
    onDelete: () => void
}

export function ProductCard({
    product,
    deleting,
    onEdit,
    onDelete,
}: ProductCardProps) {
    const locked = product.locked_by_plan

    return (
        <Card className={[
            'group overflow-hidden p-0 transition-shadow hover:shadow-sm',
            locked ? 'bg-muted/20 opacity-80' : '',
        ].join(' ')}>
            <div className="relative aspect-[4/3] overflow-hidden bg-muted">
                {product.image_path ? (
                    <img
                        src={product.image_path}
                        alt={product.name}
                        loading="lazy"
                        className="size-full object-cover transition-transform duration-300 group-hover:scale-[1.02]"
                    />
                ) : (
                    <div className="flex size-full items-center justify-center">
                        <BoxIcon className="size-7 text-muted-foreground/60" />
                    </div>
                )}

                <div className="absolute right-2 top-2">
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                type="button"
                                variant="secondary"
                                size="icon"
                                className="size-7 shadow-sm"
                                disabled={deleting || locked}
                            >
                                <MoreHorizontalIcon className="size-4" />

                                <span className="sr-only">
                                    Acciones de {product.name}
                                </span>
                            </Button>
                        </DropdownMenuTrigger>

                        <DropdownMenuContent
                            align="end"
                            className="w-40"
                        >
                            <DropdownMenuItem
                                disabled={locked}
                                onClick={onEdit}
                            >
                                Editar
                            </DropdownMenuItem>

                            <DropdownMenuSeparator />

                            <DropdownMenuItem
                                variant="destructive"
                                disabled={deleting || locked}
                                onClick={onDelete}
                            >
                                {deleting
                                    ? 'Eliminando...'
                                    : 'Eliminar'}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            <CardContent className="space-y-2 p-3">
                <div className="min-w-0">
                    <h3
                        className="truncate text-sm font-medium"
                        title={product.name}
                    >
                        {product.name}
                    </h3>

                    <div className="mt-1 flex flex-wrap items-center gap-1.5">
                        <p className="truncate text-xs text-muted-foreground">
                            {product.category.name}
                        </p>

                        {locked && (
                            <Badge
                                variant="outline"
                                className="h-5 px-1.5 text-[10px] font-normal text-muted-foreground"
                            >
                                Bloqueado por plan
                            </Badge>
                        )}
                    </div>
                </div>

                {product.description && (
                    <p className="line-clamp-2 text-xs leading-4 text-muted-foreground">
                        {product.description}
                    </p>
                )}

                <div className="flex items-center justify-between gap-2 pt-1">
                    <p className="truncate text-sm font-semibold">
                        {formatMoney(
                            product.price,
                        )}
                    </p>

                    <span
                        role="status"
                        title={
                            locked
                                ? 'Bloqueado por plan'
                                : product.is_active
                                    ? 'Activo'
                                    : 'Inactivo'
                        }
                        aria-label={
                            locked
                                ? 'Producto bloqueado por plan'
                                : product.is_active
                                    ? 'Producto activo'
                                    : 'Producto inactivo'
                        }
                        className={[
                            'size-2.5 shrink-0 rounded-full ring-2 ring-background',
                            locked
                                ? 'bg-amber-500'
                                : product.is_active
                                    ? 'bg-emerald-500'
                                    : 'bg-muted-foreground/30',
                        ].join(' ')}
                    />
                </div>
            </CardContent>
        </Card>
    )
}
