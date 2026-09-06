import {
    MoreHorizontalIcon,
    PlusIcon,
    TagsIcon,
} from 'lucide-react'
import { useState } from 'react'
import {
    useQuery,
    useQueryClient,
} from '@tanstack/react-query'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Skeleton } from '@/components/ui/skeleton'
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table'

import { useSession } from '@/features/auth/session-context'
import { queryKeys } from '@/lib/query-keys'

import {
    deleteCategory,
    getCategories,
} from './api'
import { CategoryForm } from './category-form'

import type { Category } from './types'

export function CategoriesTab() {
    const { activeTenant } = useSession()
    const queryClient = useQueryClient()

    const tenantId =
        activeTenant?.id ?? ''

    const [
        selectedCategory,
        setSelectedCategory,
    ] = useState<Category | null>(null)

    const [
        formOpen,
        setFormOpen,
    ] = useState(false)

    const [
        actionError,
        setActionError,
    ] = useState<string | null>(null)

    const [
        deletingId,
        setDeletingId,
    ] = useState<string | null>(null)

    const {
        data: categories = [],
        isPending,
        error: queryError,
    } = useQuery({
        queryKey:
            queryKeys.categories(
                tenantId,
            ),

        queryFn:
            getCategories,

        enabled:
            Boolean(tenantId),

        /*
         * Durante 5 minutos consideramos
         * las categorías actualizadas.
         *
         * Si ProductsPage utiliza esta misma
         * queryKey, no volverá a consultarlas.
         */
        staleTime:
            5 * 60 * 1000,
    })

    function handleCreate() {
        setActionError(null)

        setSelectedCategory(null)
        setFormOpen(true)
    }

    function handleEdit(
        category: Category,
    ) {
        setActionError(null)

        setSelectedCategory(
            category,
        )

        setFormOpen(true)
    }

    async function handleDelete(
        category: Category,
    ) {
        const confirmed =
            window.confirm(
                `¿Eliminar la categoría "${category.name}"?`,
            )

        if (!confirmed) {
            return
        }

        setActionError(null)
        setDeletingId(category.id)

        try {
            await deleteCategory(
                category.id,
            )

            /*
             * No hacemos otro GET.
             *
             * Eliminamos la categoría
             * directamente de la caché.
             */
            queryClient.setQueryData<
                Category[]
            >(
                queryKeys.categories(
                    tenantId,
                ),
                (current = []) =>
                    current.filter(
                        (item) =>
                            item.id !==
                            category.id,
                    ),
            )
        } catch {
            setActionError(
                'No se pudo eliminar la categoría. Puede tener productos asociados.',
            )
        } finally {
            setDeletingId(null)
        }
    }

    function handleSaved(
        savedCategory: Category,
    ) {
        /*
         * Crear y editar tampoco necesitan
         * volver a ejecutar GET /categories.
         *
         * Actualizamos la caché directamente
         * usando la categoría devuelta
         * por Laravel.
         */
        queryClient.setQueryData<
            Category[]
        >(
            queryKeys.categories(
                tenantId,
            ),
            (current = []) => {
                const exists =
                    current.some(
                        (category) =>
                            category.id ===
                            savedCategory.id,
                    )

                const next =
                    exists
                        ? current.map(
                            (category) =>
                                category.id ===
                                    savedCategory.id
                                    ? savedCategory
                                    : category,
                        )
                        : [
                            ...current,
                            savedCategory,
                        ]

                return next.sort(
                    (a, b) => {
                        if (
                            a.sort_order !==
                            b.sort_order
                        ) {
                            return (
                                a.sort_order -
                                b.sort_order
                            )
                        }

                        return a.name.localeCompare(
                            b.name,
                        )
                    },
                )
            },
        )

        setSelectedCategory(null)
        setActionError(null)
    }

    if (
        isPending &&
        categories.length === 0
    ) {
        return (
            <CategoriesSkeleton />
        )
    }

    return (
        <div className="space-y-6">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">
                        Categorías
                    </h1>

                    <p className="mt-1 text-sm text-muted-foreground">
                        Organiza los productos de tu menú.
                    </p>
                </div>

                <Button
                    onClick={handleCreate}
                >
                    <PlusIcon className="size-4" />

                    Categoría
                </Button>
            </div>

            {queryError && (
                <div className="rounded-md border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive">
                    No pudimos cargar las
                    categorías.
                </div>
            )}

            {actionError && (
                <div className="rounded-md border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive">
                    {actionError}
                </div>
            )}

            {categories.length === 0 ? (
                <EmptyCategories
                    onCreate={
                        handleCreate
                    }
                />
            ) : (
                <div className="overflow-hidden rounded-xl border bg-card">
                    <Table>
                        <TableHeader>
                            <TableRow className="bg-muted/40 hover:bg-muted/40">
                                <TableHead className="pl-5">
                                    Categoría
                                </TableHead>

                                <TableHead className="w-[140px]">
                                    Estado
                                </TableHead>

                                <TableHead className="w-[100px]">
                                    Orden
                                </TableHead>

                                <TableHead className="w-[70px] text-right">
                                    <span className="sr-only">
                                        Acciones
                                    </span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>

                        <TableBody>
                            {categories.map(
                                (category) => (
                                    <CategoryRow
                                        key={
                                            category.id
                                        }
                                        category={
                                            category
                                        }
                                        deleting={
                                            deletingId ===
                                            category.id
                                        }
                                        onEdit={() =>
                                            handleEdit(
                                                category,
                                            )
                                        }
                                        onDelete={() =>
                                            void handleDelete(
                                                category,
                                            )
                                        }
                                    />
                                ),
                            )}
                        </TableBody>
                    </Table>
                </div>
            )}

            <CategoryForm
                open={formOpen}
                onOpenChange={(
                    open,
                ) => {
                    setFormOpen(open)

                    if (!open) {
                        setSelectedCategory(
                            null,
                        )
                    }
                }}
                category={
                    selectedCategory
                }
                onSaved={
                    handleSaved
                }
            />
        </div>
    )
}

function CategoryRow({
    category,
    deleting,
    onEdit,
    onDelete,
}: {
    category: Category
    deleting: boolean
    onEdit: () => void
    onDelete: () => void
}) {
    return (
        <TableRow className="group">
            <TableCell className="py-4 pl-5">
                <div className="flex items-center gap-3">
                    <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted">
                        <TagsIcon className="size-4 text-muted-foreground" />
                    </div>

                    <div className="min-w-0">
                        <p className="truncate font-medium text-foreground">
                            {category.name}
                        </p>

                        <p className="mt-0.5 text-xs text-muted-foreground">
                            Categoría de productos
                        </p>
                    </div>
                </div>
            </TableCell>

            <TableCell>
                <Badge
                    variant={
                        category.is_active
                            ? 'secondary'
                            : 'outline'
                    }
                    className="font-normal"
                >
                    <span
                        className={[
                            'mr-1.5 size-1.5 rounded-full',
                            category.is_active
                                ? 'bg-emerald-500'
                                : 'bg-muted-foreground/50',
                        ].join(' ')}
                    />

                    {category.is_active
                        ? 'Activa'
                        : 'Inactiva'}
                </Badge>
            </TableCell>

            <TableCell>
                <span className="text-sm text-muted-foreground">
                    {category.sort_order}
                </span>
            </TableCell>

            <TableCell className="pr-4 text-right">
                <DropdownMenu>
                    <DropdownMenuTrigger
                        asChild
                    >
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            disabled={
                                deleting
                            }
                        >
                            <MoreHorizontalIcon className="size-4" />

                            <span className="sr-only">
                                Abrir acciones
                            </span>
                        </Button>
                    </DropdownMenuTrigger>

                    <DropdownMenuContent
                        align="end"
                        className="w-40"
                    >
                        <DropdownMenuItem
                            onClick={onEdit}
                        >
                            Editar
                        </DropdownMenuItem>

                        <DropdownMenuSeparator />

                        <DropdownMenuItem
                            variant="destructive"
                            disabled={
                                deleting
                            }
                            onClick={onDelete}
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
}

function EmptyCategories({
    onCreate,
}: {
    onCreate: () => void
}) {
    return (
        <div className="flex min-h-64 flex-col items-center justify-center rounded-xl border border-dashed bg-muted/10 px-6 py-10 text-center">
            <div className="flex size-12 items-center justify-center rounded-xl bg-muted">
                <TagsIcon className="size-5 text-muted-foreground" />
            </div>

            <h3 className="mt-4 text-sm font-semibold">
                Aún no tienes
                categorías
            </h3>

            <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                Crea tu primera
                categoría para comenzar
                a organizar los productos
                de tu menú.
            </p>

            <Button
                className="mt-5"
                onClick={onCreate}
            >
                <PlusIcon className="mr-2 size-4" />

                Crear categoría
            </Button>
        </div>
    )
}

function CategoriesSkeleton() {
    return (
        <div className="space-y-5">
            <div className="flex items-start justify-between gap-4">
                <div className="space-y-2">
                    <Skeleton className="h-6 w-32" />
                    <Skeleton className="h-4 w-60" />
                </div>

                <Skeleton className="h-9 w-40" />
            </div>

            <div className="overflow-hidden rounded-xl border">
                <div className="grid grid-cols-[1fr_140px_100px_70px] items-center border-b bg-muted/40 px-5 py-3">
                    <Skeleton className="h-4 w-24" />
                    <Skeleton className="h-4 w-14" />
                    <Skeleton className="h-4 w-12" />
                </div>

                {Array.from({
                    length: 4,
                }).map(
                    (_, index) => (
                        <div
                            key={index}
                            className="grid grid-cols-[1fr_140px_100px_70px] items-center border-b px-5 py-4 last:border-b-0"
                        >
                            <div className="flex items-center gap-3">
                                <Skeleton className="size-9 rounded-lg" />

                                <div className="space-y-2">
                                    <Skeleton className="h-4 w-36" />
                                    <Skeleton className="h-3 w-28" />
                                </div>
                            </div>

                            <Skeleton className="h-5 w-16 rounded-full" />

                            <Skeleton className="h-4 w-6" />

                            <div className="flex justify-end">
                                <Skeleton className="size-8" />
                            </div>
                        </div>
                    ),
                )}
            </div>
        </div>
    )
}