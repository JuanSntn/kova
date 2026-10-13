import {
    AlertCircleIcon,
} from 'lucide-react'
import {
    useState,
} from 'react'

import {
    Alert,
    AlertDescription,
} from '@/components/ui/alert'
import { Button } from '@/components/ui/button'

import { useSession } from '@/features/auth/session-context'
import { useTaxes } from '@/features/settings/taxes/hooks/use-taxes'

import { deleteProduct } from './api'
import { ProductForm } from './product-form'

import { EmptyProducts } from './components/empty-products'
import { ProductCategoryFilter } from './components/product-category-filter'
import { ProductDeleteDialog } from './components/product-delete-dialog'
import { ProductGrid } from './components/product-grid'
import { ProductUsageCard } from './components/product-usage-card'
import { ProductsHeader } from './components/products-header'
import { ProductsPageSkeleton } from './components/products-page-skeleton'
import {
    ProductsToolbar,
    type ProductViewMode,
} from './components/products-toolbar'
import { ProductsTable } from './components/products-table'

import { useProductFilters } from './hooks/use-product-filters'
import { useProductsCache } from './hooks/use-products-cache'
import { useProductsData } from './hooks/use-products-data'

import type { Product } from './types'

const PRODUCT_VIEW_STORAGE_KEY = 'kova:products:view:v1'

function readProductView(): ProductViewMode {
    try {
        const saved = window.localStorage.getItem(PRODUCT_VIEW_STORAGE_KEY)
        return saved === 'table' ? 'table' : 'cards'
    } catch {
        return 'cards'
    }
}

export function ProductsPage() {
    const { activeTenant } =
        useSession()

    const tenantId =
        activeTenant?.id ?? ''

    const [
        selectedCategory,
        setSelectedCategory,
    ] = useState('all')

    const [
        search,
        setSearch,
    ] = useState('')

    const [
        selectedProduct,
        setSelectedProduct,
    ] = useState<Product | null>(
        null,
    )

    const [
        formOpen,
        setFormOpen,
    ] = useState(false)

    const [
        productToDelete,
        setProductToDelete,
    ] = useState<Product | null>(
        null,
    )

    const [
        deletingId,
        setDeletingId,
    ] = useState<string | null>(
        null,
    )

    const [
        actionError,
        setActionError,
    ] = useState<string | null>(
        null,
    )

    const [
        viewMode,
        setViewModeState,
    ] = useState<ProductViewMode>(
        readProductView,
    )

    function setViewMode(value: ProductViewMode) {
        setViewModeState(value)
        try {
            window.localStorage.setItem(PRODUCT_VIEW_STORAGE_KEY, value)
        } catch {
            // Storage is optional: switching views must still work when blocked.
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Data
    |--------------------------------------------------------------------------
    */

    const {
        categories,
        products,
        plan,
        usage,
        hasError,
        isLoading,
        refetchAll,
    } = useProductsData(
        tenantId,
    )

    const {
        taxes,
    } = useTaxes(
        tenantId,
    )

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    const {
        categoryCounts,
        sortedCategories,
        previewCategories,
        hiddenCategories,
        filteredProducts,
    } = useProductFilters({
        products,
        categories,
        search,
        selectedCategory,
    })

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    */

    const {
        upsertProduct,
        removeProduct,
    } = useProductsCache(
        tenantId,
    )

    /*
    |--------------------------------------------------------------------------
    | Derived state
    |--------------------------------------------------------------------------
    */

    const limitReached =
        usage?.limit_reached ??
        false

    const hasOperativeCategories =
        sortedCategories.some(
            (category) => category.can_use,
        )

    const lockedProducts =
        products.filter(
            (product) =>
                product.locked_by_plan,
        )

    const lockedCategories =
        categories.filter(
            (category) =>
                category.locked_by_plan,
        )

    const hasLockedCatalog =
        lockedProducts.length > 0 ||
        lockedCategories.length > 0

    const canCreateProduct =
        !limitReached &&
        hasOperativeCategories

    /*
    |--------------------------------------------------------------------------
    | Actions
    |--------------------------------------------------------------------------
    */

    function handleCreate() {
        setActionError(null)

        setSelectedProduct(
            null,
        )

        setFormOpen(true)
    }

    function handleEdit(
        product: Product,
    ) {
        setActionError(null)

        setSelectedProduct(
            product,
        )

        setFormOpen(true)
    }

    function handleSaved(
        savedProduct: Product,
    ) {
        upsertProduct(
            savedProduct,
        )

        setSelectedProduct(
            null,
        )

        setActionError(
            null,
        )
    }

    async function handleDelete() {
        if (!productToDelete) {
            return
        }

        const product =
            productToDelete

        setDeletingId(
            product.id,
        )

        setActionError(
            null,
        )

        try {
            await deleteProduct(
                product.id,
            )

            removeProduct(
                product.id,
            )

            setProductToDelete(
                null,
            )
        } catch {
            setActionError(
                'No se pudo eliminar el producto.',
            )
        } finally {
            setDeletingId(
                null,
            )
        }
    }

    function handleRetry() {
        void refetchAll()
    }

    /*
    |--------------------------------------------------------------------------
    | Loading
    |--------------------------------------------------------------------------
    */

    if (isLoading) {
        return (
            <ProductsPageSkeleton />
        )
    }

    return (
        <div className="space-y-5">
            <ProductsHeader
                canCreate={
                    canCreateProduct
                }
                onCreate={
                    handleCreate
                }
            />

            {/*
            |--------------------------------------------------------------------------
            | Errors
            |--------------------------------------------------------------------------
            */}

            {hasError && (
                <Alert variant="destructive">
                    <AlertCircleIcon className="size-4" />

                    <AlertDescription className="flex items-center justify-between gap-4">
                        <span>
                            No pudimos cargar los productos.
                        </span>

                        <Button
                            size="sm"
                            variant="outline"
                            onClick={
                                handleRetry
                            }
                        >
                            Reintentar
                        </Button>
                    </AlertDescription>
                </Alert>
            )}

            {actionError && (
                <Alert variant="destructive">
                    <AlertCircleIcon className="size-4" />

                    <AlertDescription>
                        {actionError}
                    </AlertDescription>
                </Alert>
            )}

            {!hasOperativeCategories && (
                <Alert>
                    <AlertCircleIcon className="size-4" />

                    <AlertDescription>
                        Necesitas al menos una
                        categoría operativa antes
                        de crear productos.
                    </AlertDescription>
                </Alert>
            )}

            {hasLockedCatalog && (
                <Alert>
                    <AlertCircleIcon className="size-4" />

                    <AlertDescription>
                        Algunos productos o categorías están bloqueados por tu plan actual. Reactiva o sube tu plan para volver a editarlos o usarlos.
                    </AlertDescription>
                </Alert>
            )}

            {/*
            |--------------------------------------------------------------------------
            | Usage
            |--------------------------------------------------------------------------
            */}

            {usage && plan && (
                <ProductUsageCard
                    plan={plan}
                    usage={usage}
                />
            )}

            {/*
            |--------------------------------------------------------------------------
            | Category navigation
            |--------------------------------------------------------------------------
            */}

            <ProductCategoryFilter
                productsCount={
                    products.length
                }
                previewCategories={
                    previewCategories
                }
                hiddenCategories={
                    hiddenCategories
                }
                categoryCounts={
                    categoryCounts
                }
                selectedCategory={
                    selectedCategory
                }
                onSelectCategory={
                    setSelectedCategory
                }
            />

            {/*
            |--------------------------------------------------------------------------
            | Toolbar
            |--------------------------------------------------------------------------
            */}

            <ProductsToolbar
                search={search}
                viewMode={viewMode}
                onSearchChange={
                    setSearch
                }
                onViewModeChange={
                    setViewMode
                }
            />

            {/*
            |--------------------------------------------------------------------------
            | Products
            |--------------------------------------------------------------------------
            */}

            {filteredProducts.length ===
                0 ? (
                <EmptyProducts
                    hasProducts={
                        products.length > 0
                    }
                    hasSearch={
                        search.trim() !== ''
                    }
                    categorySelected={
                        selectedCategory !==
                        'all'
                    }
                    canCreate={
                        canCreateProduct
                    }
                    onCreate={
                        handleCreate
                    }
                />
            ) : viewMode ===
                'cards' ? (
                <ProductGrid
                    products={
                        filteredProducts
                    }
                    deletingId={
                        deletingId
                    }
                    onEdit={
                        handleEdit
                    }
                    onDelete={
                        setProductToDelete
                    }
                />
            ) : (
                <ProductsTable
                    products={
                        filteredProducts
                    }
                    deletingId={
                        deletingId
                    }
                    onEdit={
                        handleEdit
                    }
                    onDelete={
                        setProductToDelete
                    }
                />
            )}

            {/*
            |--------------------------------------------------------------------------
            | Form
            |--------------------------------------------------------------------------
            */}

            <ProductForm
                open={formOpen}
                product={
                    selectedProduct
                }
                categories={
                    categories
                }
                taxes={
                    taxes
                }
                onSaved={
                    handleSaved
                }
                onOpenChange={(
                    open,
                ) => {
                    setFormOpen(
                        open,
                    )

                    if (!open) {
                        setSelectedProduct(
                            null,
                        )
                    }
                }}
            />

            {/*
            |--------------------------------------------------------------------------
            | Delete
            |--------------------------------------------------------------------------
            */}

            <ProductDeleteDialog
                product={
                    productToDelete
                }
                deleting={
                    deletingId !== null
                }
                onOpenChange={(
                    open,
                ) => {
                    if (!open) {
                        setProductToDelete(
                            null,
                        )
                    }
                }}
                onConfirm={() => {
                    void handleDelete()
                }}
            />
        </div>
    )
}
