export type TicketBusiness = {
    name: string
    phone: string | null
    public_email: string | null
    logo_url: string | null
}

export type TicketBranch = {
    id: string
    code: string
    name: string
    phone: string | null
    postal_code: string | null
    state: string | null
    municipality: string | null
    locality: string | null
    city: string | null
    neighborhood: string | null
    street: string | null
    exterior_number: string | null
    interior_number: string | null
}

export type TicketVisibility = {
    logo: boolean
    business_name: boolean

    branch_name: boolean
    address: boolean
    rfc: boolean

    phone: boolean
    email: boolean

    cashier: boolean
    folio: boolean
    datetime: boolean
    order_type: boolean

    unit_price: boolean
    tax_breakdown: boolean

    payment_method: boolean
    cash_received: boolean
    change: boolean
}

export type TicketPrintingSettings = {
    paper_width_mm:
    | 58
    | 80

    auto_print: boolean

    copies: number
}

export type TicketSettings = {
    header_text: string | null
    footer_text: string | null
    additional_text: string | null

    visibility:
    TicketVisibility

    invoice: {
        show_qr: boolean
        qr_text: string | null
        url: string | null
        access_code: string | null
        entry_url: string | null
    }

    printing:
    TicketPrintingSettings
}

export type TicketSaleItem = {
    name: string
    quantity: number
    base_unit_price: number
    unit_price: number
    line_total: number
    modifiers: {
        group_name: string
        option_name: string
        price_adjustment: number
    }[]
}

export type TicketSale = {
    id: string

    folio: string | null
    paid_at: string | null
    order_type: string

    cashier: string | null

    subtotal: number
    tax_total: number
    total: number

    payment_method: string

    amount_received:
    number | null

    change_amount:
    number | null

    items:
    TicketSaleItem[]
}

export type TicketDocument = {
    timezone: string

    business:
    TicketBusiness

    branch: TicketBranch | null

    settings:
    TicketSettings

    sale:
    TicketSale
}

export type TicketResponse = {
    data: TicketDocument
}
