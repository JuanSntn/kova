export const ticketQueryKeys = {
    all: (
        tenantId: string,
    ) =>
        [
            'tickets',
            tenantId,
        ] as const,

    order: (
        tenantId: string,
        orderId: string,
    ) =>
        [
            ...ticketQueryKeys.all(
                tenantId,
            ),
            'order',
            orderId,
        ] as const,
}