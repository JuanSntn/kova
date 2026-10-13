import { CatalogUsageCard } from './catalog-usage-card'

import type {
    ProductPlan,
    ProductUsage,
} from '../types'

type ProductUsageCardProps = {
    plan: ProductPlan
    usage: ProductUsage
}

export function ProductUsageCard({
    plan,
    usage,
}: ProductUsageCardProps) {
    return (
        <CatalogUsageCard
            label="Uso de productos"
            unit="productos"
            plan={plan}
            usage={usage}
        />
    )
}
