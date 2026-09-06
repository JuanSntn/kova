import { useQuery } from '@tanstack/react-query'

import {
    getSalesHistory,
} from '../api'

import {
    salesQueryKeys,
} from '../query-keys'

import type {
    SalesFilters,
} from '../types'

export function useSalesHistory(
    tenantId: string,
    filters: SalesFilters,
) {
    return useQuery({
        queryKey:
            salesQueryKeys.history(
                tenantId,
                filters,
            ),

        queryFn: () =>
            getSalesHistory(
                filters
            ),

        enabled:
            Boolean(tenantId),

        placeholderData:
            (previous) =>
                previous,
    })
}