import { useQuery } from '@tanstack/react-query'

import {
    getSalesSummary,
} from '../api'

import {
    salesQueryKeys,
} from '../query-keys'

export function useSalesSummary(
    tenantId: string,
) {
    return useQuery({
        queryKey:
            salesQueryKeys.summary(
                tenantId,
            ),

        queryFn:
            getSalesSummary,

        enabled:
            Boolean(tenantId),

        staleTime:
            60 * 1000,
    })
}