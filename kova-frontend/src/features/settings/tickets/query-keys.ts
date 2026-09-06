export const ticketSettingsQueryKeys = {
    detail: (
        tenantId: string,
    ) =>
        [
            'ticket-settings',
            tenantId,
        ] as const,
}