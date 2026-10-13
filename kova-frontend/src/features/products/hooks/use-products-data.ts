import { useQuery } from '@tanstack/react-query'

import { queryKeys } from '@/lib/query-keys'

import {
    getCategories,
    getProducts,
} from '../api'

export function useProductsData(
    tenantId: string,
) {
    const categoriesQuery =
        useQuery({
            queryKey:
                queryKeys.categories(
                    tenantId,
                ),

            queryFn:
                getCategories,

            enabled:
                Boolean(tenantId),

            staleTime:
                5 * 60 * 1000,
        })

    const productsQuery =
        useQuery({
            queryKey:
                queryKeys.products(
                    tenantId,
                ),

            queryFn:
                getProducts,

            enabled:
                Boolean(tenantId),

            staleTime:
                5 * 60 * 1000,
        })

    const categories =
        categoriesQuery.data ?? []

    const productsResult =
        productsQuery.data

    const products =
        productsResult?.data ?? []

    const plan =
        productsResult?.meta?.plan ??
        null

    const usage =
        productsResult?.meta?.usage ??
        null

    const categoryUsage =
        productsResult?.meta?.category_usage ??
        null

    const hasError =
        categoriesQuery.isError ||
        productsQuery.isError

    const isLoading =
        (
            categoriesQuery.isPending &&
            categories.length === 0
        ) ||
        (
            productsQuery.isPending &&
            !productsResult
        )

    function refetchAll() {
        return Promise.all([
            categoriesQuery.refetch(),
            productsQuery.refetch(),
        ])
    }

    return {
        categories,
        products,
        plan,
        usage,
        categoryUsage,
        hasError,
        isLoading,
        refetchAll,
    }
}