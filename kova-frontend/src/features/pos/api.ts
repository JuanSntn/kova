import { api } from '@/lib/api'

import type {
    CheckoutContext,
    CheckoutQuote,
    PosCategory,
    PosProductsResponse,
} from './types'

type DataResponse<T> = {
    data: T
}

/*
|--------------------------------------------------------------------------
| Categories
|--------------------------------------------------------------------------
*/

export async function getPosCategories(): Promise<
    PosCategory[]
> {
    const response =
        await api.get<
            DataResponse<PosCategory[]>
        >(
            '/api/pos/catalog/categories',
        )

    return response.data.data
}

/*
|--------------------------------------------------------------------------
| Products
|--------------------------------------------------------------------------
*/

export async function getPosProducts(): Promise<
    PosProductsResponse
> {
    const response =
        await api.get<
            PosProductsResponse
        >(
            '/api/pos/catalog/products',
        )

    return response.data
}

/*
|--------------------------------------------------------------------------
| Checkout
|--------------------------------------------------------------------------
*/

import type {
    CreatePosOrderPayload,
    PosCreatedOrder,
    PosOrderResponse,
} from './types'

export async function createPosOrder(
    payload: CreatePosOrderPayload,
    key: string,
    context: CheckoutContext,
): Promise<PosCreatedOrder> {
    const response =
        await api.post<PosOrderResponse>(
            '/api/pos/orders',
            payload,
            { headers: checkoutHeaders(context, key), timeout: 20000 },
        )

    return response.data.data
}

function checkoutHeaders(context: CheckoutContext, key?: string) {
    return { 'X-Tenant-ID': context.tenant, 'X-Branch-ID': context.branch, ...(key ? { 'Idempotency-Key': key } : {}) }
}

export async function quotePosOrder(items: CreatePosOrderPayload['items'], context: CheckoutContext): Promise<CheckoutQuote> {
    return (await api.post('/api/pos/checkout/quote', { items }, { headers: checkoutHeaders(context), timeout: 20000 })).data.data
}

export async function recoverPosOrder(key: string, context: CheckoutContext): Promise<PosCreatedOrder> {
    return (await api.get('/api/pos/checkouts/' + key, { headers: checkoutHeaders(context), timeout: 20000 })).data.data
}
