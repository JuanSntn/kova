import type {
    SalesFilters,
} from './types'

export const salesQueryKeys = {
    all: (
        tenantId: string,
    ) => [
        'sales',
        tenantId,
    ] as const,

    summary: (
        tenantId: string,
    ) => [
        ...salesQueryKeys.all(
            tenantId,
        ),
        'summary',
    ] as const,

    history: (
        tenantId: string,
        filters: SalesFilters,
    ) => [
        ...salesQueryKeys.all(
            tenantId,
        ),
        'history',
        filters,
    ] as const,

    detail: (
        tenantId: string,
        saleId: string,
    ) => [
        ...salesQueryKeys.all(
            tenantId,
        ),
        'detail',
        saleId,
    ] as const,
}