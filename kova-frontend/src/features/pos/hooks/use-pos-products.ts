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
    branchId: string,
) {
    /*
    |--------------------------------------------------------------------------
    | Categories
    |--------------------------------------------------------------------------
    */

    const categoriesQuery =
        useQuery({
            queryKey:
                queryKeys.posCategories(tenantId, branchId),

            queryFn:
                getPosCategories,

            enabled:
                Boolean(
                    tenantId && branchId,
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
                queryKeys.posProducts(tenantId, branchId),

            queryFn:
                getPosProducts,

            enabled:
                Boolean(
                    tenantId && branchId,
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
                    product.is_active &&
                    product.can_use,
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
