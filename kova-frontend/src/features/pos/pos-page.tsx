import {
    AlertCircleIcon,
} from 'lucide-react'
import {
    useState,
} from 'react'
import {
    toast,
} from 'sonner'

import {
    Alert,
    AlertDescription,
} from '@/components/ui/alert'
import {
    Button,
} from '@/components/ui/button'
import {
    Skeleton,
} from '@/components/ui/skeleton'

import {
    useSession,
} from '@/features/auth/session-context'

import {
    CheckoutSheet,
} from './components/checkout-sheet'
import {
    PosCart,
} from './components/pos-cart'
import {
    PosCategoryFilter,
} from './components/pos-category-filter'
import {
    PosProductGrid,
} from './components/pos-product-grid'
import {
    PosSearch,
} from './components/pos-search'

import {
    usePosCart,
} from './hooks/use-pos-cart'
import {
    usePosCheckout,
} from './hooks/use-pos-checkout'
import {
    usePosFilters,
} from './hooks/use-pos-filters'
import {
    usePosProducts,
} from './hooks/use-pos-products'

import type {
    CheckoutReceipt,
    PosCartItem,
    PosCartTotals,
    PosPaymentMethod,
} from './types'

export function PosPage() {
    /*
    |--------------------------------------------------------------------------
    | Tenant
    |--------------------------------------------------------------------------
    */

    const {
        activeTenant,
    } = useSession()

    const tenantId =
        activeTenant?.id ?? ''

    /*
    |--------------------------------------------------------------------------
    | Catalog
    |--------------------------------------------------------------------------
    */

    const {
        categories,
        products,

        isLoading,
        hasError,

        refetchAll,
    } = usePosProducts(
        tenantId,
    )

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    const {
        search,
        setSearch,

        selectedCategory,
        setSelectedCategory,

        categoryCounts,

        visibleCategories,

        filteredProducts,

        clearFilters,
    } = usePosFilters({
        products,
        categories,
    })

    /*
    |--------------------------------------------------------------------------
    | Cart
    |--------------------------------------------------------------------------
    */

    const {
        items,
        itemCount,
        totals,

        paymentMethod,
        setPaymentMethod,

        addProduct,
        increaseQuantity,
        decreaseQuantity,
        removeItem,
        clearCart,
    } = usePosCart()

    /*
    |--------------------------------------------------------------------------
    | Checkout state
    |--------------------------------------------------------------------------
    |
    | Cuando el usuario presiona "Cobrar" congelamos
    | una fotografía del carrito.
    |
    | Esto evita que el checkout dependa del carrito
    | vivo mientras está abierto.
    |
    */

    const [
        checkoutOpen,
        setCheckoutOpen,
    ] = useState(
        false,
    )

    const [
        checkoutItems,
        setCheckoutItems,
    ] = useState<
        PosCartItem[]
    >([])

    const [
        checkoutTotals,
        setCheckoutTotals,
    ] = useState<
        PosCartTotals | null
    >(null)

    const [
        checkoutPaymentMethod,
        setCheckoutPaymentMethod,
    ] =
        useState<PosPaymentMethod>(
            'cash',
        )

    const [
        checkoutReceipt,
        setCheckoutReceipt,
    ] =
        useState<CheckoutReceipt | null>(
            null,
        )

    /*
    |--------------------------------------------------------------------------
    | Checkout mutation
    |--------------------------------------------------------------------------
    */

    const {
        createOrder,

        isPending:
        checkingOut,

        reset:
        resetCheckout,
    } = usePosCheckout({
        onError: () => {
            /*
             * Importante:
             *
             * NO limpiamos el carrito si Laravel
             * rechaza la venta.
             */
            toast.error(
                'No pudimos registrar la venta. Tu carrito se conserva.',
            )
        },
    })

    /*
    |--------------------------------------------------------------------------
    | Open checkout
    |--------------------------------------------------------------------------
    |
    | Cobrar NO crea todavía la orden.
    |
    | Únicamente abre el Sheet.
    |
    */

    function handleCheckout() {
        if (
            items.length ===
            0
        ) {
            return
        }

        /*
         * Congelamos los items.
         */
        setCheckoutItems([
            ...items,
        ])

        /*
         * Congelamos los totales visibles.
         *
         * Laravel volverá a calcular los valores
         * reales al confirmar.
         */
        setCheckoutTotals({
            ...totals,
        })

        /*
         * Congelamos el método elegido en el carrito.
         */
        setCheckoutPaymentMethod(
            paymentMethod,
        )

        /*
         * Cada nuevo checkout comienza
         * sin ticket previo.
         */
        setCheckoutReceipt(
            null,
        )

        resetCheckout()

        setCheckoutOpen(
            true,
        )
    }

    /*
    |--------------------------------------------------------------------------
    | Confirm checkout
    |--------------------------------------------------------------------------
    |
    | Aquí sí hacemos:
    |
    | POST /api/pos/orders
    |
    */

    function handleConfirmCheckout(
        cashData: {
            amountReceived?:
            number
        },
    ) {
        if (
            checkoutItems.length ===
            0 ||
            !checkoutTotals
        ) {
            return
        }

        const payload = {
            order_type:
                'counter' as const,

            payment_method:
                checkoutPaymentMethod,

            /*
             * Solamente efectivo manda
             * amount_received.
             */
            ...(checkoutPaymentMethod ===
                'cash'
                ? {
                    amount_received:
                        cashData
                            .amountReceived,
                }
                : {}),

            items:
                checkoutItems.map(
                    (item) => ({
                        product_id:
                            item.product_id,

                        quantity:
                            item.quantity,
                    }),
                ),
        }

        createOrder(
            payload,
            {
                onSuccess:
                    (order) => {
                        /*
                         * Todo lo oficial viene
                         * dentro de order:
                         *
                         * total
                         * impuestos
                         * amount_received
                         * change_amount
                         * folio
                         */
                        setCheckoutReceipt({
                            order,

                            items:
                                checkoutItems,

                            totals:
                                checkoutTotals,

                            payment_method:
                                checkoutPaymentMethod,
                        })

                        clearCart()
                        clearFilters()
                    },
            },
        )
    }

    /*
    |--------------------------------------------------------------------------
    | Checkout finished
    |--------------------------------------------------------------------------
    |
    | Se ejecuta desde:
    |
    | Venta completada
    | → Nueva venta
    |
    */

    function handleCheckoutFinished() {
        setCheckoutOpen(
            false,
        )

        setCheckoutReceipt(
            null,
        )

        setCheckoutItems(
            [],
        )

        setCheckoutTotals(
            null,
        )

        resetCheckout()

        clearFilters()

        toast.success(
            'Nueva venta lista.',
        )
    }

    /*
    |--------------------------------------------------------------------------
    | Close checkout
    |--------------------------------------------------------------------------
    |
    | Si el usuario cancela ANTES de pagar,
    | el carrito original permanece intacto.
    |
    */

    function handleCheckoutOpenChange(
        open: boolean,
    ) {
        /*
         * Mientras Laravel está procesando
         * evitamos cerrar el checkout.
         */
        if (
            checkingOut
        ) {
            return
        }

        setCheckoutOpen(
            open,
        )

        if (open) {
            return
        }

        /*
         * Cerramos y limpiamos únicamente
         * el estado temporal del checkout.
         *
         * NO llamamos clearCart().
         */
        setCheckoutReceipt(
            null,
        )

        setCheckoutItems(
            [],
        )

        setCheckoutTotals(
            null,
        )

        resetCheckout()
    }

    /*
    |--------------------------------------------------------------------------
    | Loading
    |--------------------------------------------------------------------------
    */

    if (isLoading) {
        return (
            <PosPageSkeleton />
        )
    }

    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    return (
        <>
            <div className="space-y-4">

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
                                No pudimos cargar el
                                catálogo del punto de
                                venta.
                            </span>

                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    void refetchAll()
                                }
                            >
                                Reintentar
                            </Button>
                        </AlertDescription>
                    </Alert>
                )}

                {/*
                |--------------------------------------------------------------------------
                | POS Layout
                |--------------------------------------------------------------------------
                */}

                <div className="grid min-w-0 gap-4 xl:grid-cols-[minmax(0,1fr)_380px]">

                    {/*
                    |--------------------------------------------------------------------------
                    | Catalog
                    |--------------------------------------------------------------------------
                    */}

                    <section className="min-w-0 overflow-hidden rounded-xl border bg-background">
                        {/*
                         * Search + Categories
                         */}

                        <div className="space-y-2 border-b p-3">
                            <PosSearch
                                value={
                                    search
                                }
                                onChange={
                                    setSearch
                                }
                            />

                            <PosCategoryFilter
                                categories={
                                    visibleCategories
                                }
                                categoryCounts={
                                    categoryCounts
                                }
                                productsCount={
                                    products.length
                                }
                                selectedCategory={
                                    selectedCategory
                                }
                                onSelectCategory={
                                    setSelectedCategory
                                }
                            />
                        </div>

                        {/*
                         * Products
                         */}

                        <div className="p-3">
                            <PosProductGrid
                                products={
                                    filteredProducts
                                }
                                hasCatalogProducts={
                                    products.length >
                                    0
                                }
                                onAdd={
                                    addProduct
                                }
                                onClearFilters={
                                    clearFilters
                                }
                            />
                        </div>
                    </section>

                    {/*
                    |--------------------------------------------------------------------------
                    | Cart
                    |--------------------------------------------------------------------------
                    */}

                    <aside className="min-w-0">
                        <PosCart
                            items={
                                items
                            }
                            itemCount={
                                itemCount
                            }
                            totals={
                                totals
                            }
                            paymentMethod={
                                paymentMethod
                            }
                            onPaymentMethodChange={
                                setPaymentMethod
                            }
                            onIncrease={
                                increaseQuantity
                            }
                            onDecrease={
                                decreaseQuantity
                            }
                            onRemove={
                                removeItem
                            }
                            onClear={
                                clearCart
                            }
                            onCheckout={
                                handleCheckout
                            }
                        />
                    </aside>
                </div>
            </div>

            {/*
            |--------------------------------------------------------------------------
            | Checkout Sheet
            |--------------------------------------------------------------------------
            |
            | Solo existe mientras tengamos una
            | fotografía válida del carrito.
            |
            */}

            {checkoutTotals && (
                <CheckoutSheet
                    open={
                        checkoutOpen
                    }
                    onOpenChange={
                        handleCheckoutOpenChange
                    }
                    items={
                        checkoutItems
                    }
                    totals={
                        checkoutTotals
                    }
                    paymentMethod={
                        checkoutPaymentMethod
                    }
                    checkingOut={
                        checkingOut
                    }
                    receipt={
                        checkoutReceipt
                    }
                    onConfirm={
                        handleConfirmCheckout
                    }
                    onNewSale={
                        handleCheckoutFinished
                    }
                />
            )}
        </>
    )
}

/*
|--------------------------------------------------------------------------
| Skeleton
|--------------------------------------------------------------------------
*/

function PosPageSkeleton() {
    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <div className="space-y-2">
                    <Skeleton className="h-6 w-40" />

                    <Skeleton className="h-4 w-28" />
                </div>

                <Skeleton className="h-9 w-28" />
            </div>

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_380px]">

                {/*
                 * Catalog
                 */}

                <div className="rounded-xl border p-4">
                    <Skeleton className="h-10 w-full" />

                    <div className="mt-3 flex gap-2 overflow-hidden">
                        {Array.from({
                            length:
                                6,
                        }).map(
                            (
                                _,
                                index,
                            ) => (
                                <Skeleton
                                    key={
                                        index
                                    }
                                    className="h-8 w-24 shrink-0"
                                />
                            ),
                        )}
                    </div>

                    <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-3 2xl:grid-cols-4">
                        {Array.from({
                            length:
                                12,
                        }).map(
                            (
                                _,
                                index,
                            ) => (
                                <div
                                    key={
                                        index
                                    }
                                    className="overflow-hidden rounded-xl border"
                                >
                                    <Skeleton className="aspect-[4/3] w-full rounded-none" />

                                    <div className="space-y-2 p-3">
                                        <Skeleton className="h-4 w-3/4" />

                                        <Skeleton className="h-3 w-1/2" />

                                        <Skeleton className="h-4 w-20" />
                                    </div>
                                </div>
                            ),
                        )}
                    </div>
                </div>

                {/*
                 * Cart
                 */}

                <div className="rounded-xl border p-4">
                    <Skeleton className="h-6 w-32" />

                    <Skeleton className="mt-2 h-4 w-24" />

                    <div className="mt-6 space-y-3">
                        {Array.from({
                            length:
                                4,
                        }).map(
                            (
                                _,
                                index,
                            ) => (
                                <Skeleton
                                    key={
                                        index
                                    }
                                    className="h-24 w-full"
                                />
                            ),
                        )}
                    </div>
                </div>
            </div>
        </div>
    )
}