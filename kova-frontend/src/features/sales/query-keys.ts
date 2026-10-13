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
        branchId: string,
    ) => [
        ...salesQueryKeys.all(
            tenantId,
        ),
        branchId,
        'summary',
    ] as const,

    history: (
        tenantId: string,
        branchId: string,
        filters: SalesFilters,
    ) => [
        ...salesQueryKeys.all(
            tenantId,
        ),
        branchId,
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
