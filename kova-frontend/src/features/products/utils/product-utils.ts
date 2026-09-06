import type {
    Product,
    ProductUsage,
} from '../types'

export function incrementUsage(
    usage: ProductUsage,
): ProductUsage {
    const used =
        usage.used + 1

    const remaining =
        usage.limit === null
            ? null
            : Math.max(
                usage.limit - used,
                0,
            )

    return {
        ...usage,
        used,
        remaining,

        limit_reached:
            usage.limit !== null &&
            used >= usage.limit,
    }
}

export function decrementUsage(
    usage: ProductUsage,
): ProductUsage {
    const used =
        Math.max(
            usage.used - 1,
            0,
        )

    const remaining =
        usage.limit === null
            ? null
            : Math.max(
                usage.limit - used,
                0,
            )

    return {
        ...usage,
        used,
        remaining,

        limit_reached:
            usage.limit !== null &&
            used >= usage.limit,
    }
}

export function sortProducts(
    products: Product[],
): Product[] {
    return [...products].sort(
        (a, b) => {
            if (
                a.sort_order !==
                b.sort_order
            ) {
                return (
                    a.sort_order -
                    b.sort_order
                )
            }

            return a.name.localeCompare(
                b.name,
                'es',
            )
        },
    )
}

export function formatMoney(
    value: string | number,
): string {
    const amount =
        Number(value)

    if (
        !Number.isFinite(
            amount,
        )
    ) {
        return '$0'
    }

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
    ).format(amount)
}