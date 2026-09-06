import { useQuery } from '@tanstack/react-query'

import { queryKeys } from '@/lib/query-keys'

import {
    getTaxConfig,
    getTaxes,
} from '../api'

export function useTaxes(
    tenantId: string,
) {
    const taxesQuery =
        useQuery({
            queryKey:
                queryKeys.taxes(
                    tenantId,
                ),
            queryFn:
                getTaxes,
            enabled:
                Boolean(
                    tenantId,
                ),
            staleTime:
                5 * 60 * 1000,
        })

    const configQuery =
        useQuery({
            queryKey: [
                ...queryKeys.taxes(
                    tenantId,
                ),
                'config',
            ] as const,
            queryFn:
                getTaxConfig,
            enabled:
                Boolean(
                    tenantId,
                ),
            staleTime:
                5 * 60 * 1000,
        })

    return {
        taxes:
            taxesQuery.data ?? [],
        config:
            configQuery.data ?? null,
        isLoading:
            taxesQuery.isPending ||
            configQuery.isPending,
        hasError:
            taxesQuery.isError ||
            configQuery.isError,
        refetchAll: () =>
            Promise.all([
                taxesQuery.refetch(),
                configQuery.refetch(),
            ]),
    }
}
