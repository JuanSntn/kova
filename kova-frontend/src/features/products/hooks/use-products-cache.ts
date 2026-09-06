import { useQueryClient } from '@tanstack/react-query'

import { queryKeys } from '@/lib/query-keys'

import {
    decrementUsage,
    incrementUsage,
    sortProducts,
} from '../utils/product-utils'

import type {
    Product,
    ProductsResponse,
} from '../types'

export function useProductsCache(
    tenantId: string,
) {
    const queryClient =
        useQueryClient()

    function upsertProduct(
        savedProduct: Product,
    ) {
        queryClient.setQueryData<
            ProductsResponse
        >(
            queryKeys.products(
                tenantId,
            ),

            (current) => {
                if (!current) {
                    return current
                }

                const exists =
                    current.data.some(
                        (product) =>
                            product.id ===
                            savedProduct.id,
                    )

                const nextProducts =
                    exists
                        ? current.data.map(
                            (product) =>
                                product.id ===
                                    savedProduct.id
                                    ? savedProduct
                                    : product,
                        )
                        : [
                            ...current.data,
                            savedProduct,
                        ]

                const nextResponse:
                    ProductsResponse =
                {
                    ...current,

                    data:
                        sortProducts(
                            nextProducts,
                        ),
                }

                if (
                    current.meta &&
                    !exists
                ) {
                    nextResponse.meta = {
                        ...current.meta,

                        usage:
                            incrementUsage(
                                current.meta
                                    .usage,
                            ),
                    }
                }

                return nextResponse
            },
        )
    }

    function removeProduct(
        productId: string,
    ) {
        queryClient.setQueryData<
            ProductsResponse
        >(
            queryKeys.products(
                tenantId,
            ),

            (current) => {
                if (!current) {
                    return current
                }

                const exists =
                    current.data.some(
                        (product) =>
                            product.id ===
                            productId,
                    )

                if (!exists) {
                    return current
                }

                const nextResponse:
                    ProductsResponse =
                {
                    ...current,

                    data:
                        current.data.filter(
                            (product) =>
                                product.id !==
                                productId,
                        ),
                }

                if (current.meta) {
                    nextResponse.meta = {
                        ...current.meta,

                        usage:
                            decrementUsage(
                                current.meta
                                    .usage,
                            ),
                    }
                }

                return nextResponse
            },
        )
    }

    return {
        upsertProduct,
        removeProduct,
    }
}