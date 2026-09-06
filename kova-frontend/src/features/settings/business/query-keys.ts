export const businessSettingsQueryKeys = {
    detail: (
        tenantId: string,
    ) =>
        [
            'business-settings',
            tenantId,
        ] as const,
}