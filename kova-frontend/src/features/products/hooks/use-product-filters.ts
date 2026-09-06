import { useMemo } from 'react'

import type {
    Category,
    Product,
} from '../types'

const CATEGORY_PREVIEW_LIMIT = 5

type UseProductFiltersProps = {
    products: Product[]
    categories: Category[]
    search: string
    selectedCategory: string
}

export function useProductFilters({
    products,
    categories,
    search,
    selectedCategory,
}: UseProductFiltersProps) {
    const categoryCounts =
        useMemo(() => {
            const counts =
                new Map<string, number>()

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
        }, [products])

    const sortedCategories =
        useMemo(() => {
            return [...categories]
                .filter(
                    (category) =>
                        category.is_active,
                )
                .sort((a, b) => {
                    const countA =
                        categoryCounts.get(
                            a.id,
                        ) ?? 0

                    const countB =
                        categoryCounts.get(
                            b.id,
                        ) ?? 0

                    if (
                        countA !==
                        countB
                    ) {
                        return (
                            countB -
                            countA
                        )
                    }

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
                })
        }, [
            categories,
            categoryCounts,
        ])

    const previewCategories =
        useMemo(() => {
            const top =
                sortedCategories.slice(
                    0,
                    CATEGORY_PREVIEW_LIMIT,
                )

            if (
                selectedCategory ===
                'all'
            ) {
                return top
            }

            const isVisible =
                top.some(
                    (category) =>
                        category.id ===
                        selectedCategory,
                )

            if (isVisible) {
                return top
            }

            const selected =
                sortedCategories.find(
                    (category) =>
                        category.id ===
                        selectedCategory,
                )

            if (!selected) {
                return top
            }

            if (
                top.length <
                CATEGORY_PREVIEW_LIMIT
            ) {
                return [
                    ...top,
                    selected,
                ]
            }

            return [
                ...top.slice(
                    0,
                    CATEGORY_PREVIEW_LIMIT -
                    1,
                ),
                selected,
            ]
        }, [
            sortedCategories,
            selectedCategory,
        ])

    const hiddenCategories =
        useMemo(() => {
            const visibleIds =
                new Set(
                    previewCategories.map(
                        (category) =>
                            category.id,
                    ),
                )

            return sortedCategories.filter(
                (category) =>
                    !visibleIds.has(
                        category.id,
                    ),
            )
        }, [
            sortedCategories,
            previewCategories,
        ])

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

                    const matchesSearch =
                        normalizedSearch ===
                        '' ||
                        product.name
                            .toLowerCase()
                            .includes(
                                normalizedSearch,
                            ) ||
                        product.category.name
                            .toLowerCase()
                            .includes(
                                normalizedSearch,
                            )

                    return (
                        matchesCategory &&
                        matchesSearch
                    )
                },
            )
        }, [
            products,
            search,
            selectedCategory,
        ])

    return {
        categoryCounts,
        sortedCategories,
        previewCategories,
        hiddenCategories,
        filteredProducts,
    }
}