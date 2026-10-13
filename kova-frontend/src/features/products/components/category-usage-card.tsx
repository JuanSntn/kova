import { CatalogUsageCard } from './catalog-usage-card'

import type {
    CategoryUsage,
    ProductPlan,
} from '../types'

type CategoryUsageCardProps = {
    plan: ProductPlan
    usage: CategoryUsage
}

export function CategoryUsageCard({
    plan,
    usage,
}: CategoryUsageCardProps) {
    return (
        <CatalogUsageCard
            label="Uso de categorías"
            unit="categorías"
            plan={plan}
            usage={usage}
        />
    )
}
