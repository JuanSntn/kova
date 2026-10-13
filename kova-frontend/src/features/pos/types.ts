export type PosCategory = {
    id: string
    name: string
    sort_order: number
    is_active: boolean
    billing_enabled: boolean
    locked_by_plan: boolean
    can_use: boolean
}

export type PosProduct = {
    modifier_groups?: import('@/features/modifiers/types').ProductModifierGroup[]
    id: string
    name: string
    description: string | null

    price:
    | string
    | number

    image_path: string | null

    sort_order: number

    is_active: boolean

    billing_enabled: boolean

    locked_by_plan: boolean

    can_use: boolean

    category: {
        id: string
        name: string
        billing_enabled: boolean
        locked_by_plan: boolean
    }
}

export type PosProductsResponse = {
    data: PosProduct[]

    /*
     * El endpoint administrativo puede
     * devolver meta del plan.
     *
     * El POS no depende de él todavía.
     */
    meta?: unknown
}

export type PosCheckoutPayload = {
    order_type: PosOrderType
    payment_method: PosPaymentMethod
    items: Array<{
        product_id: string
        quantity: number
    }>
}

export type PosOrder = {
    id: string
    folio: string
    total: string
    payment_method: PosPaymentMethod
}

/*
|--------------------------------------------------------------------------
| Order
|--------------------------------------------------------------------------
*/

export type PosOrderType =
    | 'counter'

export type PosPaymentMethod =
    | 'cash'
    | 'card'
    | 'transfer'

/*
|--------------------------------------------------------------------------
| Modifiers
|--------------------------------------------------------------------------
|
| Free no utilizará modificadores.
|
| Dejamos la estructura preparada porque
| Negocio y Restaurante sí podrán tenerlos.
|
*/

export type PosCartModifier = {
    modifier_option_id: string

    group_name: string

    option_name: string

    price_adjustment: number
}

/*
|--------------------------------------------------------------------------
| Cart
|--------------------------------------------------------------------------
*/

export type PosCartItem = {
    /*
     * Identificador único de la línea.
     *
     * En Free coincide con product_id.
     *
     * Cuando agreguemos modificadores podrá
     * convertirse en:
     *
     * producto + firma modificadores
     */
    line_id: string

    product_id: string

    name_snapshot: string

    category_name: string

    image_path: string | null

    unit_price: number

    quantity: number

    modifiers: PosCartModifier[]

    notes: string | null
}

export type PosCartTotals = {
    subtotal: number

    discount_total: number

    service_charge_total: number

    tip_total: number

    total: number
}

/*
|--------------------------------------------------------------------------
| Checkout
|--------------------------------------------------------------------------
*/

export type CreatePosOrderItemPayload = {
    product_id: string
    quantity: number
    modifiers?: string[]
}

export type CreatePosOrderPayload = {
    quote_token: string
    order_type:
    'counter'

    payment_method:
    PosPaymentMethod

    amount_received?:
    number | string

    items:
    CreatePosOrderItemPayload[]
}

export type PosCreatedOrder = {
    inventory_warnings?: string[]
    items: Array<{ product_id: string | null; product_name: string; unit_price: string; quantity: number; line_total: string; modifiers?: import('@/features/modifiers/types').ModifierSnapshot[] }>
    id: string

    folio?:
    string | null

    status:
    string

    order_type:
    string

    payment_method:
    PosPaymentMethod

    subtotal:
    | string
    | number

    tax_base_total?:
    | string
    | number

    tax_total?:
    | string
    | number

    total:
    | string
    | number

    paid_at?:
    string | null

    amount_received?:
    | string
    | number
    | null

    change_amount?:
    | string
    | number
    | null
}

export type CheckoutContext = { tenant: string; branch: string; membership: string }
export type CheckoutQuote = {
    quote_token: string
    expires_at: number
    subtotal: string
    total: string
    tax_base_total: string
    tax_total: string
    lines: Array<{ product_id: string; product_name_snapshot: string; unit_price_snapshot: string; quantity: number; line_total: string; modifiers_snapshot?: import('@/features/modifiers/types').ModifierSnapshot[] }>
}

export type PosOrderResponse = {
    data:
    PosCreatedOrder
}
export type CheckoutReceipt = {
    order:
    PosCreatedOrder

    items:
    PosCartItem[]

    totals:
    PosCartTotals

    payment_method:
    PosPaymentMethod
}
