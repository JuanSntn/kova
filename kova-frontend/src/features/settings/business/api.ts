import { api } from '@/lib/api'

import type {
    BusinessProfile,
    BusinessProfileResponse,
} from './types'

export async function getBusinessProfile(): Promise<
    BusinessProfile
> {
    const response =
        await api.get<
            BusinessProfileResponse
        >(
            '/api/settings/business',
        )

    return response.data.data
}

export async function updateBusinessProfile(
    formData: FormData,
): Promise<BusinessProfile> {
    const response =
        await api.post<
            BusinessProfileResponse
        >(
            '/api/settings/business',
            formData,
        )

    return response.data.data
}