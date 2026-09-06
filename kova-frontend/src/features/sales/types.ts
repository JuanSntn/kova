export type PaymentMethod =
    | 'cash'
    | 'card'
    | 'transfer'

export type SalesSummary = {
    timezone: string

    plan: {
        code: string
        name: string
    }

    period: {
        type:
        | 'calendar_month'
        | 'billing_cycle'

        starts_at: string
        ends_at: string
    }

    metrics: {
        total_sales: number
        transaction_count: number
        average_ticket: number

        sales_limit: number | null
        sales_limit_used: number
        sales_limit_remaining: number | null
        sales_limit_percentage: number | null
    }

    top_products: Array<{
        name: string
        units: number
        sales_total: number
    }>

    payment_methods: Array<{
        method: PaymentMethod
        transactions: number
        percentage: number
        sales_total: number
    }>

    sales_by_day: Array<{
        date: string
        total: number
        transactions: number
    }>
}

export type SaleListItem = {
    id: string
    folio: string
    status: string
    payment_method: PaymentMethod
    total: number
    paid_at: string
}

export type SaleDetail = {
    id: string
    folio: string
    status: string
    order_type: string
    payment_method: PaymentMethod

    subtotal: number
    tax_base_total: number
    tax_total: number
    discount_total: number
    service_charge_total: number
    tip_total: number
    total: number

    amount_received: number | null
    change_amount: number | null

    paid_at: string

    items: Array<{
        name: string
        unit_price: number
        quantity: number
        tax_name: string
        tax_rate: number
        tax_base: number
        tax_amount: number
        line_total: number
    }>
}

export type SalesFilters = {
    search?: string
    date_from?: string
    date_to?: string
    payment_method?: PaymentMethod
    page: number
    per_page: number
}

export type SalesHistoryResponse = {
    data: SaleListItem[]

    meta: {
        current_page: number
        last_page: number
        per_page: number
        total: number
    }
}