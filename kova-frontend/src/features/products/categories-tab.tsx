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
    getCategoriesWithMeta,
} from './api'
import { CategoryForm } from './category-form'
import { CategoryUsageCard } from './components/category-usage-card'

import type {
    CategoriesResponse,
    Category,
} from './types'

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
        data: categoriesResult,
        isPending,
        error: queryError,
    } = useQuery({
        queryKey:
            queryKeys.categoryCatalog(
                tenantId,
            ),

        queryFn:
            getCategoriesWithMeta,

        enabled:
            Boolean(tenantId),

        /*
         * Esta caché conserva también el plan
         * y el uso; las listas simples de POS
         * utilizan una clave independiente.
         */
        staleTime:
            5 * 60 * 1000,
    })

    const categories =
        categoriesResult?.data ?? []

    const plan =
        categoriesResult?.meta?.plan ??
        null

    const usage =
        categoriesResult?.meta?.usage ??
        null

    const hasLockedCategories =
        categories.some(
            (category) =>
                category.locked_by_plan,
        )

    const limitReached =
        usage?.limit_reached ??
        false

    const canCreateCategory =
        !limitReached &&
        !hasLockedCategories

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
            queryClient.setQueryData<CategoriesResponse>(
                queryKeys.categoryCatalog(
                    tenantId,
                ),
                (current) => current
                    ? {
                        ...current,
                        data: current.data.filter(
                            (item) =>
                                item.id !==
                                category.id,
                        ),
                    }
                    : current,
            )

            void queryClient.invalidateQueries({
                queryKey: queryKeys.categoryCatalog(
                    tenantId,
                ),
            })

            void queryClient.invalidateQueries({
                queryKey: queryKeys.categories(
                    tenantId,
                ),
            })
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
        queryClient.setQueryData<CategoriesResponse>(
            queryKeys.categoryCatalog(
                tenantId,
            ),
            (current) => {
                if (!current) {
                    return current
                }

                const exists =
                    current.data.some(
                        (category) =>
                            category.id ===
                            savedCategory.id,
                    )

                const next =
                    exists
                        ? current.data.map(
                            (category) =>
                                category.id ===
                                    savedCategory.id
                                    ? savedCategory
                                    : category,
                        )
                        : [
                            ...current.data,
                            savedCategory,
                        ]

                return {
                    ...current,
                    data: next.sort(
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
                    ),
                }
            },
        )

        void queryClient.invalidateQueries({
            queryKey: queryKeys.categoryCatalog(
                tenantId,
            ),
        })

        void queryClient.invalidateQueries({
            queryKey: queryKeys.categories(
                tenantId,
            ),
        })

        setSelectedCategory(null)
        setActionError(null)
    }

    if (
        isPending &&
        !categoriesResult
    ) {
        return (
            <CategoriesSkeleton />
        )
    }

    return (
        <div className="space-y-5">
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
                    size="sm"
                    disabled={!canCreateCategory}
                    title={!canCreateCategory ? 'Tu plan actual ya alcanzó el cupo de categorías operativas.' : undefined}
                    onClick={handleCreate}
                >
                    <PlusIcon className="size-4" />

                    Categoría
                </Button>
            </div>

            {plan && usage && (
                <CategoryUsageCard
                    plan={plan}
                    usage={usage}
                />
            )}

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

            {hasLockedCategories && (
                <div className="rounded-md border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
                    Algunas categorías están bloqueadas por tu plan actual. Reactiva o sube tu plan para volver a editarlas o usarlas.
                </div>
            )}

            {categories.length === 0 ? (
                <EmptyCategories
                    canCreate={
                        canCreateCategory
                    }
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
    const locked = category.locked_by_plan

    return (
        <TableRow className={locked ? 'group bg-muted/20' : 'group'}>
            <TableCell className="py-4 pl-5">
                <div className="flex items-center gap-3">
                    <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted">
                        <TagsIcon className="size-4 text-muted-foreground" />
                    </div>

                    <div className="min-w-0">
                        <p className="truncate font-medium text-foreground">
                            {category.name}
                        </p>

                        <div className="mt-1 flex flex-wrap items-center gap-1.5">
                            <p className="text-xs text-muted-foreground">
                                Categoría de productos
                            </p>

                            {locked && (
                                <Badge
                                    variant="outline"
                                    className="h-5 px-1.5 text-[10px] font-normal text-muted-foreground"
                                >
                                    Bloqueada por plan
                                </Badge>
                            )}
                        </div>
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
                            locked
                                ? 'bg-amber-500'
                                : category.is_active
                                    ? 'bg-emerald-500'
                                    : 'bg-muted-foreground/50',
                        ].join(' ')}
                    />

                    {locked
                        ? 'Bloqueada'
                        : category.is_active
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
                                deleting || locked
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
                            disabled={locked}
                            onClick={onEdit}
                        >
                            Editar
                        </DropdownMenuItem>

                        <DropdownMenuSeparator />

                        <DropdownMenuItem
                            variant="destructive"
                            disabled={
                                deleting || locked
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
    canCreate,
    onCreate,
}: {
    canCreate: boolean
    onCreate: () => void
}) {
    return (
        <div className="flex min-h-52 flex-col items-center justify-center rounded-xl border border-dashed bg-muted/10 p-8 text-center">
            <div className="flex size-11 items-center justify-center rounded-xl bg-muted">
                <TagsIcon className="size-5 text-muted-foreground" />
            </div>

            <h3 className="mt-4 text-sm font-medium">
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
                size="sm"
                className="mt-5"
                disabled={!canCreate}
                onClick={onCreate}
            >
                <PlusIcon className="size-4" />

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
