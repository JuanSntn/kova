import type { CatalogUsage, ProductPlan } from '../types'

type CatalogUsageCardProps = {
    label: string
    unit: string
    plan: ProductPlan
    usage: CatalogUsage
}

// Both catalogue screens use the same compact disclosure.
export function CatalogUsageCard({ label, unit, plan, usage }: CatalogUsageCardProps) {
    return (
        <details className="w-fit max-w-full text-xs text-muted-foreground">
            <summary
                aria-label={`${label}: ${usage.used}${!usage.is_unlimited && usage.limit !== null ? ` de ${usage.limit}` : ''} ${unit}. Ver detalles del límite.`}
                className="min-h-6 cursor-pointer rounded-sm py-1 marker:text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
            >
                <span className="tabular-nums">
                    {usage.used}
                    {!usage.is_unlimited && usage.limit !== null && ` de ${usage.limit}`}
                    {' '}{unit}
                </span>
                {usage.is_unlimited && <span> · Sin límite</span>}
                {usage.limit_reached && <span className="font-medium text-destructive"> · Límite alcanzado</span>}
            </summary>
            <div className="mt-1 space-y-1 border-l pl-3 leading-5">
                <p>Plan {plan.name}</p>
                <p>
                    {usage.is_unlimited
                        ? `Tu plan no establece un límite de ${unit}.`
                        : usage.remaining !== null
                            ? `${usage.remaining} ${unit} disponibles en tu plan.`
                            : 'El límite lo determina tu suscripción vigente.'}
                </p>
            </div>
        </details>
    )
}
