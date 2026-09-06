import {
    useQuery,
} from '@tanstack/react-query'

import {
    queryKeys,
} from '@/lib/query-keys'

import {
    getPosCategories,
    getPosProducts,
} from '../api'

import type {
    PosCategory,
    PosProduct,
} from '../types'

const POS_STALE_TIME =
    5 * 60 * 1000

export function usePosProducts(
    tenantId: string,
) {
    /*
    |--------------------------------------------------------------------------
    | Categories
    |--------------------------------------------------------------------------
    */

    const categoriesQuery =
        useQuery({
            queryKey:
                queryKeys.categories(
                    tenantId,
                ),

            queryFn:
                getPosCategories,

            enabled:
                Boolean(
                    tenantId,
                ),

            staleTime:
                POS_STALE_TIME,
        })

    /*
    |--------------------------------------------------------------------------
    | Products
    |--------------------------------------------------------------------------
    */

    const productsQuery =
        useQuery({
            queryKey:
                queryKeys.products(
                    tenantId,
                ),

            queryFn:
                getPosProducts,

            enabled:
                Boolean(
                    tenantId,
                ),

            staleTime:
                POS_STALE_TIME,
        })

    /*
    |--------------------------------------------------------------------------
    | Active categories
    |--------------------------------------------------------------------------
    */

    const categories:
        PosCategory[] =
        (
            categoriesQuery.data ??
            []
        )
            .filter(
                (category) =>
                    category.is_active,
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

    /*
    |--------------------------------------------------------------------------
    | Active products
    |--------------------------------------------------------------------------
    */

    const products:
        PosProduct[] =
        (
            productsQuery
                .data
                ?.data ??
            []
        )
            .filter(
                (product) =>
                    product.is_active,
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

    /*
    |--------------------------------------------------------------------------
    | States
    |--------------------------------------------------------------------------
    */

    const isLoading =
        (
            categoriesQuery.isPending &&
            !categoriesQuery.data
        ) ||
        (
            productsQuery.isPending &&
            !productsQuery.data
        )

    const hasError =
        categoriesQuery.isError ||
        productsQuery.isError

    /*
    |--------------------------------------------------------------------------
    | Refetch
    |--------------------------------------------------------------------------
    */

    function refetchAll() {
        return Promise.all([
            categoriesQuery.refetch(),
            productsQuery.refetch(),
        ])
    }

    return {
        categories,
        products,

        isLoading,
        hasError,

        refetchAll,
    }
}