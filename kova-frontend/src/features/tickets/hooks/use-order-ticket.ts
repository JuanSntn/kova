import {
    useQuery,
} from '@tanstack/react-query'

import {
    getOrderTicket,
} from '../api'

import {
    ticketQueryKeys,
} from '../query-keys'

export function useOrderTicket(
    tenantId: string,
    orderId:
        | string
        | null,
    enabled = true,
) {
    return useQuery({
        queryKey:
            ticketQueryKeys.order(
                tenantId,
                orderId ?? '',
            ),

        queryFn: () =>
            getOrderTicket(
                orderId!,
            ),

        enabled:
            enabled &&
            Boolean(
                tenantId
            ) &&
            Boolean(
                orderId
            ),
    })
}