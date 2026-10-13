import { useQuery } from '@tanstack/react-query'

import {
    getSalesSummary,
} from '../api'

import {
    salesQueryKeys,
} from '../query-keys'

export function useSalesSummary(
    tenantId: string,
    branchId: string,
) {
    return useQuery({
        queryKey:
            salesQueryKeys.summary(
                tenantId,
                branchId,
            ),

        queryFn:
            getSalesSummary,

        enabled:
            Boolean(tenantId && branchId),

        staleTime:
            60 * 1000,
    })
}
