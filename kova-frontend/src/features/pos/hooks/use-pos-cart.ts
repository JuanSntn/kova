import {
    useCallback,
    useMemo,
    useState,
} from 'react'

import type {
    PosCartItem,
    PosCartTotals,
    PosPaymentMethod,
    PosProduct,
} from '../types'
import { cents } from '../money'

const MAX_QUANTITY_PER_LINE =
    99

function createLineId(
    product: PosProduct,
    selected: string[],
): string {
    /*
     * Free:
     *
     * un producto = una línea.
     *
     * Después podremos incluir
     * modificadores en esta firma.
     */
    return [product.id, ...selected.slice().sort()].join(':')
}

export function usePosCart(initialItems: PosCartItem[] = [], initialPayment: PosPaymentMethod = 'cash') {
    const [
        items,
        setItems,
    ] = useState<
        PosCartItem[]
    >(initialItems)

    /*
    |--------------------------------------------------------------------------
    | Payment method
    |--------------------------------------------------------------------------
    */

    const [
        paymentMethod,
        setPaymentMethod,
    ] =
        useState<PosPaymentMethod>(
            initialPayment,
        )

    /*
    |--------------------------------------------------------------------------
    | Add product
    |--------------------------------------------------------------------------
    */

    const addProduct =
        useCallback(
            (
                product:
                    PosProduct,
                selected: string[] = [],
            ) => {
                const lineId =
                    createLineId(
                        product,
                        selected,
                    )

                setItems(
                    (current) => {
                        const existing =
                            current.find(
                                (item) =>
                                    item.line_id ===
                                    lineId,
                            )

                        if (existing) {
                            return current.map(
                                (item) => {
                                    if (
                                        item.line_id !==
                                        lineId
                                    ) {
                                        return item
                                    }

                                    return {
                                        ...item,

                                        quantity:
                                            Math.min(
                                                item.quantity +
                                                1,

                                                MAX_QUANTITY_PER_LINE,
                                            ),
                                    }
                                },
                            )
                        }

                        return [
                            ...current,

                            {
                                line_id:
                                    lineId,

                                product_id:
                                    product.id,

                                name_snapshot:
                                    product.name + (selected.length ? ' · ' + (product.modifier_groups ?? []).flatMap(g => g.options).filter(o => selected.includes(o.id)).map(o => o.name).join(', ') : ''),

                                category_name:
                                    product
                                        .category
                                        .name,

                                image_path:
                                    product.image_path,

                                unit_price:
                                    (cents(product.price) + (product.modifier_groups ?? []).flatMap(g => g.options).filter(o => selected.includes(o.id)).reduce((sum, o) => sum + cents(o.price), 0)) / 100,

                                quantity:
                                    1,

                                modifiers:
                                    (product.modifier_groups ?? []).flatMap(g => g.options.filter(o => selected.includes(o.id)).map(o => ({ modifier_option_id: o.id, group_name: g.name, option_name: o.name, price_adjustment: Number(o.price) }))),

                                notes:
                                    null,
                            },
                        ]
                    },
                )
            },
            [],
        )

    /*
    |--------------------------------------------------------------------------
    | Increase
    |--------------------------------------------------------------------------
    */

    const increaseQuantity =
        useCallback(
            (
                lineId: string,
            ) => {
                setItems(
                    (current) =>
                        current.map(
                            (item) =>
                                item.line_id ===
                                    lineId
                                    ? {
                                        ...item,

                                        quantity:
                                            Math.min(
                                                item.quantity +
                                                1,

                                                MAX_QUANTITY_PER_LINE,
                                            ),
                                    }
                                    : item,
                        ),
                )
            },
            [],
        )

    /*
    |--------------------------------------------------------------------------
    | Decrease
    |--------------------------------------------------------------------------
    */

    const decreaseQuantity =
        useCallback(
            (
                lineId: string,
            ) => {
                setItems(
                    (current) =>
                        current
                            .map(
                                (item) =>
                                    item.line_id ===
                                        lineId
                                        ? {
                                            ...item,

                                            quantity:
                                                item.quantity -
                                                1,
                                        }
                                        : item,
                            )
                            .filter(
                                (item) =>
                                    item.quantity >
                                    0,
                            ),
                )
            },
            [],
        )

    /*
    |--------------------------------------------------------------------------
    | Remove
    |--------------------------------------------------------------------------
    */

    const removeItem =
        useCallback(
            (
                lineId: string,
            ) => {
                setItems(
                    (current) =>
                        current.filter(
                            (item) =>
                                item.line_id !==
                                lineId,
                        ),
                )
            },
            [],
        )

    /*
    |--------------------------------------------------------------------------
    | Clear
    |--------------------------------------------------------------------------
    */

    const clearCart =
        useCallback(
            () => {
                setItems([])

                /*
                 * Toda nueva venta
                 * comienza en efectivo.
                 */
                setPaymentMethod(
                    'cash',
                )
            },
            [],
        )

    /*
    |--------------------------------------------------------------------------
    | Item count
    |--------------------------------------------------------------------------
    */

    const itemCount =
        useMemo(() => {
            return items.reduce(
                (
                    total,
                    item,
                ) =>
                    total +
                    item.quantity,

                0,
            )
        }, [
            items,
        ])

    /*
    |--------------------------------------------------------------------------
    | Totals
    |--------------------------------------------------------------------------
    */

    const totals:
        PosCartTotals =
        useMemo(() => {
            const subtotal =
                items.reduce(
                    (
                        total,
                        item,
                    ) =>
                        total +
                        (
                            cents(item.unit_price) *
                            item.quantity
                        ),

                    0,
                ) / 100

            /*
             * Free MVP.
             */
            const discountTotal =
                0

            const serviceChargeTotal =
                0

            const tipTotal =
                0

            const total =
                subtotal -
                discountTotal +
                serviceChargeTotal +
                tipTotal

            return {
                subtotal,

                discount_total:
                    discountTotal,

                service_charge_total:
                    serviceChargeTotal,

                tip_total:
                    tipTotal,

                total,
            }
        }, [
            items,
        ])

    return {
        setItems,
        items,

        itemCount,

        hasItems:
            items.length >
            0,

        totals,

        paymentMethod,
        setPaymentMethod,

        addProduct,

        increaseQuantity,

        decreaseQuantity,

        removeItem,

        clearCart,
    }
}
