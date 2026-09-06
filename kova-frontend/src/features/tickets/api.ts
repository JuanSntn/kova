import {
    api,
} from '@/lib/api'

import type {
    TicketDocument,
    TicketResponse,
} from './types'

export async function getOrderTicket(
    orderId: string,
): Promise<TicketDocument> {
    const response =
        await api.get<TicketResponse>(
            `/api/pos/orders/${encodeURIComponent(
                orderId,
            )}/ticket`,
        )

    return response.data.data
}