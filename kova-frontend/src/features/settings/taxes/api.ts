import axios from 'axios'

import { api } from '@/lib/api'

import type {
    Tax,
    TaxConfig,
    TaxUpdatePayload,
} from './types'

type DataResponse<T> = {
    data: T
}

type ApiErrorResponse = {
    message?: string
    errors?: Record<string, string[]>
}

export async function getTaxes(): Promise<Tax[]> {
    const response =
        await api.get<DataResponse<Tax[]>>(
            '/api/settings/taxes',
        )

    return response.data.data
}

export async function getTaxConfig(): Promise<TaxConfig> {
    const response =
        await api.get<DataResponse<TaxConfig>>(
            '/api/settings/taxes/config',
        )

    return response.data.data
}

export async function updateTax(
    taxId: string,
    payload: TaxUpdatePayload,
): Promise<Tax> {
    const response =
        await api.put<DataResponse<Tax>>(
            `/api/settings/taxes/${taxId}`,
            payload,
        )

    return response.data.data
}

export async function updateTaxConfig(
    defaultTaxId: string,
): Promise<TaxConfig> {
    const response =
        await api.put<DataResponse<TaxConfig>>(
            '/api/settings/taxes/config',
            {
                default_tax_id:
                    defaultTaxId,
            },
        )

    return response.data.data
}

export function getTaxApiErrorMessage(
    error: unknown,
): string {
    if (!axios.isAxiosError<ApiErrorResponse>(error)) {
        return 'Ocurrió un error inesperado.'
    }

    const errors =
        error.response?.data?.errors

    if (errors) {
        const first =
            Object.values(errors)[0]

        if (Array.isArray(first) && first[0]) {
            return first[0]
        }
    }

    return error.response?.data?.message ??
        'Ocurrió un error inesperado.'
}
