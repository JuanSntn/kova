import {
    useMutation,
} from '@tanstack/react-query'

import {
    createPosOrder,
} from '../api'

import type {
    CreatePosOrderPayload,
    PosCreatedOrder,
} from '../types'

type UsePosCheckoutOptions = {
    onSuccess?: (
        order: PosCreatedOrder
    ) => void

    onError?: (
        error: unknown
    ) => void
}

export function usePosCheckout({
    onSuccess,
    onError,
}: UsePosCheckoutOptions = {}) {
    const mutation =
        useMutation({
            mutationFn:
                (
                    payload:
                        CreatePosOrderPayload,
                ) =>
                    createPosOrder(
                        payload,
                    ),

            onSuccess:
                (order) => {
                    onSuccess?.(
                        order,
                    )
                },

            onError:
                (error) => {
                    onError?.(
                        error,
                    )
                },
        })

    return {
        createOrder:
            mutation.mutate,

        createOrderAsync:
            mutation.mutateAsync,

        isPending:
            mutation.isPending,

        error:
            mutation.error,

        reset:
            mutation.reset,
    }
}