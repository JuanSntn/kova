import {
    useEffect,
    useMemo,
    useState,
} from 'react'

import type {
    PosCategory,
    PosProduct,
} from '../types'

type UsePosFiltersProps = {
    products: PosProduct[]

    categories: PosCategory[]
}

export function usePosFilters({
    products,
    categories,
}: UsePosFiltersProps) {
    const [
        search,
        setSearch,
    ] = useState('')

    const [
        selectedCategory,
        setSelectedCategory,
    ] = useState('all')

    /*
    |--------------------------------------------------------------------------
    | Product count by category
    |--------------------------------------------------------------------------
    */

    const categoryCounts =
        useMemo(() => {
            const counts =
                new Map<
                    string,
                    number
                >()

            for (
                const product
                of products
            ) {
                const categoryId =
                    product.category.id

                counts.set(
                    categoryId,
                    (
                        counts.get(
                            categoryId,
                        ) ?? 0
                    ) + 1,
                )
            }

            return counts
        }, [
            products,
        ])

    /*
    |--------------------------------------------------------------------------
    | Categories
    |--------------------------------------------------------------------------
    */

    const visibleCategories =
        useMemo(() => {
            return [
                ...categories,
            ]
                .filter(
                    (category) =>
                        category.is_active &&
                        category.can_use,
                )
                .sort(
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

                        return a.name
                            .localeCompare(
                                b.name,
                                'es',
                            )
                    },
                )
        }, [
            categories,
        ])

    /*
     * Si la categoría seleccionada
     * deja de existir o se desactiva,
     * regresamos a Todos.
     */
    useEffect(() => {
        if (
            selectedCategory ===
            'all'
        ) {
            return
        }

        const exists =
            visibleCategories.some(
                (category) =>
                    category.id ===
                    selectedCategory,
            )

        if (!exists) {
            setSelectedCategory(
                'all',
            )
        }
    }, [
        selectedCategory,
        visibleCategories,
    ])

    /*
    |--------------------------------------------------------------------------
    | Products
    |--------------------------------------------------------------------------
    */

    const filteredProducts =
        useMemo(() => {
            const normalizedSearch =
                search
                    .trim()
                    .toLowerCase()

            return products.filter(
                (product) => {
                    const matchesCategory =
                        selectedCategory ===
                        'all' ||
                        product.category.id ===
                        selectedCategory

                    if (
                        !matchesCategory
                    ) {
                        return false
                    }

                    if (
                        normalizedSearch ===
                        ''
                    ) {
                        return true
                    }

                    const searchableText =
                        [
                            product.name,

                            product.description ??
                            '',

                            product.category
                                .name,
                        ]
                            .join(' ')
                            .toLowerCase()

                    return searchableText
                        .includes(
                            normalizedSearch,
                        )
                },
            )
        }, [
            products,
            search,
            selectedCategory,
        ])

    /*
    |--------------------------------------------------------------------------
    | Clear
    |--------------------------------------------------------------------------
    */

    function clearFilters() {
        setSearch('')

        setSelectedCategory(
            'all',
        )
    }

    return {
        search,
        setSearch,

        selectedCategory,
        setSelectedCategory,

        categoryCounts,

        visibleCategories,

        filteredProducts,

        clearFilters,
    }
}