import { api } from '@/lib/api'

import type {
    PosCategory,
    PosCheckoutPayload,
    PosOrder,
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
            '/api/pos/categories',
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
            '/api/pos/products',
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
): Promise<PosCreatedOrder> {
    const response =
        await api.post<PosOrderResponse>(
            '/api/pos/orders',
            payload,
        )

    return response.data.data
}


