export function formatTicketMoney(
    value: number,
): string {
    return new Intl.NumberFormat(
        'es-MX',
        {
            style:
                'currency',

            currency:
                'MXN',

            minimumFractionDigits:
                2,

            maximumFractionDigits:
                2,
        },
    ).format(
        value,
    )
}

export function formatTicketDateTime(
    value:
        | string
        | null,
    timezone?: string | null,
): string {
    if (!value) {
        return ''
    }

    const date =
        new Date(
            value,
        )

    if (
        Number.isNaN(
            date.getTime(),
        )
    ) {
        return value
    }

    const options:
        Intl.DateTimeFormatOptions = {
        dateStyle:
            'medium',

        timeStyle:
            'short',
    }

    if (timezone) {
        options.timeZone =
            timezone
    }

    try {
        return new Intl.DateTimeFormat(
            'es-MX',
            options,
        ).format(
            date,
        )
    } catch {
        return new Intl.DateTimeFormat(
            'es-MX',
            {
                dateStyle:
                    'medium',

                timeStyle:
                    'short',
            },
        ).format(
            date,
        )
    }
}

export function ticketPaymentMethodLabel(
    method: string,
): string {
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

export function ticketOrderTypeLabel(
    type: string,
): string {
    switch (type) {
        case 'counter':
            return 'Mostrador'

        case 'table':
            return 'Mesa'

        default:
            return type
    }
}