import {
    PackageSearchIcon,
} from 'lucide-react'

import {
    Button,
} from '@/components/ui/button'

import {
    PosProductCard,
} from './pos-product-card'

import type {
    PosProduct,
} from '../types'

type PosProductGridProps = {
    products:
    PosProduct[]

    hasCatalogProducts:
    boolean

    onAdd: (
        product: PosProduct
    ) => void

    onClearFilters?:
    () => void
}

export function PosProductGrid({
    products,
    hasCatalogProducts,
    onAdd,
    onClearFilters,
}: PosProductGridProps) {
    /*
    |--------------------------------------------------------------------------
    | Orden de productos
    |--------------------------------------------------------------------------
    |
    | Los productos se muestran según sort_order:
    |
    | 1 → primero
    | 2 → segundo
    | 3 → tercero
    |
    | Si dos productos tienen el mismo sort_order,
    | usamos el nombre como segundo criterio.
    |
    */

    const sortedProducts =
        [...products].sort(
            (
                productA,
                productB,
            ) => {
                /*
                 * Convertimos por seguridad porque
                 * puede llegar como number o string.
                 */
                const rawOrderA =
                    Number(
                        productA.sort_order,
                    )

                const rawOrderB =
                    Number(
                        productB.sort_order,
                    )

                /*
                 * En Kova:
                 *
                 * 1 = primero
                 * 2 = segundo
                 * 3 = tercero
                 *
                 * 0, null, undefined o inválido
                 * = producto sin orden, va al final.
                 */

                const orderA =
                    Number.isFinite(
                        rawOrderA,
                    ) &&
                        rawOrderA > 0
                        ? rawOrderA
                        : Number.MAX_SAFE_INTEGER

                const orderB =
                    Number.isFinite(
                        rawOrderB,
                    ) &&
                        rawOrderB > 0
                        ? rawOrderB
                        : Number.MAX_SAFE_INTEGER

                /*
                 * Primero respetamos sort_order.
                 */
                if (
                    orderA !==
                    orderB
                ) {
                    return (
                        orderA -
                        orderB
                    )
                }

                /*
                 * Si ambos no tienen orden
                 * o tienen el mismo sort_order,
                 * ordenamos por nombre.
                 */
                return productA.name.localeCompare(
                    productB.name,
                    'es',
                )
            },
        )

    /*
    |--------------------------------------------------------------------------
    | Empty
    |--------------------------------------------------------------------------
    */

    if (
        sortedProducts.length ===
        0
    ) {
        return (
            <div className="flex min-h-52 flex-col items-center justify-center rounded-xl border border-dashed bg-muted/10 p-6 text-center">
                <div className="flex size-10 items-center justify-center rounded-lg bg-muted">
                    <PackageSearchIcon className="size-4 text-muted-foreground" />
                </div>

                <h3 className="mt-3 text-sm font-medium">
                    {hasCatalogProducts
                        ? 'No encontramos productos'
                        : 'No hay productos disponibles'}
                </h3>

                <p className="mt-1 max-w-sm text-xs text-muted-foreground">
                    {hasCatalogProducts
                        ? 'Prueba cambiando la búsqueda o seleccionando otra categoría.'
                        : 'Activa o crea productos desde el catálogo para poder venderlos.'}
                </p>

                {hasCatalogProducts &&
                    onClearFilters && (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            className="mt-3 h-8 text-xs"
                            onClick={
                                onClearFilters
                            }
                        >
                            Limpiar filtros
                        </Button>
                    )}
            </div>
        )
    }

    /*
    |--------------------------------------------------------------------------
    | Products
    |--------------------------------------------------------------------------
    */

    return (
        <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-5">
            {sortedProducts.map(
                (product) => (
                    <PosProductCard
                        key={
                            product.id
                        }
                        product={
                            product
                        }
                        onAdd={
                            onAdd
                        }
                    />
                ),
            )}
        </div>
    )
}