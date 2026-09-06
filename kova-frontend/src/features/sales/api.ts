import { api } from '@/lib/api'

import type {
    SaleDetail,
    SalesFilters,
    SalesHistoryResponse,
    SalesSummary,
} from './types'

type DataResponse<T> = {
    data: T
}

/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

export async function getSalesSummary(): Promise<
    SalesSummary
> {
    const response =
        await api.get<
            DataResponse<SalesSummary>
        >(
            '/api/pos/sales/summary',
        )

    return response.data.data
}

/*
|--------------------------------------------------------------------------
| History
|--------------------------------------------------------------------------
*/

export async function getSalesHistory(
    filters: SalesFilters,
): Promise<SalesHistoryResponse> {
    const response =
        await api.get<
            SalesHistoryResponse
        >(
            '/api/pos/sales',
            {
                params: {
                    search:
                        filters.search ||
                        undefined,

                    date_from:
                        filters.date_from ||
                        undefined,

                    date_to:
                        filters.date_to ||
                        undefined,

                    payment_method:
                        filters.payment_method ||
                        undefined,

                    page:
                        filters.page,

                    per_page:
                        filters.per_page,
                },
            },
        )

    return response.data
}

/*
|--------------------------------------------------------------------------
| Sale detail
|--------------------------------------------------------------------------
*/

export async function getSale(
    saleId: string,
): Promise<SaleDetail> {
    const response =
        await api.get<
            DataResponse<SaleDetail>
        >(
            `/api/pos/sales/${encodeURIComponent(
                saleId,
            )}`,
        )

    return response.data.data
}