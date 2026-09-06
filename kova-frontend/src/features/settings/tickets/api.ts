import {
    api,
} from '@/lib/api'

import type {
    TicketSettingsResponse,
    UpdateTicketSettingsPayload,
} from './types'

export async function getTicketSettings(): Promise<
    TicketSettingsResponse
> {
    const response =
        await api.get<
            TicketSettingsResponse
        >(
            '/api/settings/ticket',
        )

    return response.data
}

export async function updateTicketSettings(
    payload:
        UpdateTicketSettingsPayload,
): Promise<TicketSettingsResponse> {
    const response =
        await api.put<
            TicketSettingsResponse
        >(
            '/api/settings/ticket',
            payload,
        )

    return response.data
}