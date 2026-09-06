import {
    Button,
} from '@/components/ui/button'

import type {
    PosCategory,
} from '../types'

type PosCategoryFilterProps = {
    categories:
    PosCategory[]

    categoryCounts:
    Map<
        string,
        number
    >

    productsCount:
    number

    selectedCategory:
    string

    onSelectCategory: (
        categoryId: string
    ) => void
}


export function PosCategoryFilter({
    categories,
    categoryCounts,
    productsCount,
    selectedCategory,
    onSelectCategory,


}: PosCategoryFilterProps) {

    /*
    |--------------------------------------------------------------------------
    | Categorías visibles
    |--------------------------------------------------------------------------
    |
    | 1. Quitamos categorías que no tengan productos.
    | 2. Ordenamos de mayor a menor cantidad de productos.
    |
    | Ejemplo:
    |
    | Hamburguesas  4
    | Bebidas       3
    | Postres       1
    |
    */

    const sortedCategories =
        categories
            /*
             * Solo categorías que tengan
             * productos disponibles en el POS.
             */
            .filter(
                (category) =>
                    (
                        categoryCounts.get(
                            category.id,
                        ) ?? 0
                    ) > 0,
            )

            /*
             * Respetamos el orden configurado
             * por el usuario.
             *
             * 1 → primero
             * 2 → segundo
             * 3 → tercero
             */
            .sort(
                (
                    categoryA,
                    categoryB,
                ) => {
                    const orderA =
                        categoryA.sort_order ??
                        0

                    const orderB =
                        categoryB.sort_order ??
                        0

                    /*
                     * Primero sort_order.
                     */
                    if (
                        orderA !==
                        orderB
                    ) {
                        return (
                            orderA -
                            orderB
                        )
                    }

                    /*
                     * Si tienen el mismo
                     * sort_order, usamos nombre.
                     */
                    return categoryA.name.localeCompare(
                        categoryB.name,
                        'es',
                    )
                },
            )

    return (
        <div className="flex gap-2 overflow-x-auto pb-1">

            {/*
            |--------------------------------------------------------------------------
            | Todos
            |--------------------------------------------------------------------------
            */}

            <Button
                type="button"
                size="sm"
                variant={
                    selectedCategory ===
                        'all'
                        ? 'default'
                        : 'outline'
                }
                className="h-8 shrink-0 gap-1.5 px-3 text-xs"
                onClick={() =>
                    onSelectCategory(
                        'all',
                    )
                }
            >
                Todos

                <span className="text-[10px] opacity-70">
                    {
                        productsCount
                    }
                </span>
            </Button>

            {/*
            |--------------------------------------------------------------------------
            | Categorías
            |--------------------------------------------------------------------------
            */}

            {sortedCategories.map(
                (category) => {
                    const count =
                        categoryCounts.get(
                            category.id,
                        ) ?? 0

                    return (
                        <Button
                            key={
                                category.id
                            }
                            type="button"
                            size="sm"
                            variant={
                                selectedCategory ===
                                    category.id
                                    ? 'default'
                                    : 'outline'
                            }
                            className="h-8 shrink-0 gap-1.5 px-3 text-xs"
                            onClick={() =>
                                onSelectCategory(
                                    category.id,
                                )
                            }
                        >
                            <span className="max-w-36 truncate">
                                {
                                    category.name
                                }
                            </span>

                            <span className="text-[10px] opacity-70">
                                {
                                    count
                                }
                            </span>
                        </Button>
                    )
                },
            )}
        </div>
    )
}