import type {
    PaymentMethod,
} from './types'

export function formatMoney(
    value: number,
) {
    return new Intl.NumberFormat(
        'es-MX',
        {
            style: 'currency',
            currency: 'MXN',
        },
    ).format(value)
}

export function formatDateTime(
    value: string,
    timezone?: string,
) {
    return new Intl.DateTimeFormat(
        'es-MX',
        {
            dateStyle: 'medium',
            timeStyle: 'short',
            timeZone:
                timezone ||
                undefined,
        },
    ).format(
        new Date(value),
    )
}

export function paymentMethodLabel(
    method: PaymentMethod,
) {
    switch (method) {
        case 'cash':
            return 'Efectivo'

        case 'card':
            return 'Tarjeta'

        case 'transfer':
            return 'Transferencia'

        default:
            return method
    }
}