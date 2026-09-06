import { ProductCard } from './product-card'

import type { Product } from '../types'

type ProductGridProps = {
    products: Product[]
    deletingId: string | null
    onEdit: (product: Product) => void
    onDelete: (product: Product) => void
}

export function ProductGrid({
    products,
    deletingId,
    onEdit,
    onDelete,
}: ProductGridProps) {
    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-7">            {products.map(
            (product) => (
                <ProductCard
                    key={product.id}
                    product={product}
                    deleting={
                        deletingId ===
                        product.id
                    }
                    onEdit={() =>
                        onEdit(product)
                    }
                    onDelete={() =>
                        onDelete(product)
                    }
                />
            ),
        )}
        </div>
    )
}