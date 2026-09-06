export type BusinessProfile = {
    name: string
    phone: string | null
    public_email: string | null
    logo_url: string | null

    is_complete: boolean

    missing_fields: string[]
}

export type BusinessProfileResponse = {
    data: BusinessProfile
}