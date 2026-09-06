import { useQuery } from '@tanstack/react-query'

import {
    getSale,
} from '../api'

import {
    salesQueryKeys,
} from '../query-keys'

export function useSaleDetail(
    tenantId: string,
    saleId: string | null,
) {
    return useQuery({
        queryKey:
            salesQueryKeys.detail(
                tenantId,
                saleId ?? '',
            ),

        queryFn: () =>
            getSale(
                saleId!
            ),

        enabled:
            Boolean(
                tenantId
                && saleId
            ),
    })
}