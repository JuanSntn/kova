export type TicketVisibility = {
    logo: boolean
    business_name: boolean
    branch_name: boolean
    address: boolean
    phone: boolean
    email: boolean
    rfc: boolean
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

export type TicketSettings = {
    folio_prefix: string

    header_text: string | null
    footer_text: string | null
    additional_text: string | null

    visibility: TicketVisibility

    invoice: {
        show_qr: boolean
        qr_text: string | null
    }

    printing: {
        paper_width_mm:
        | 58
        | 80

        auto_print: boolean

        copies: number
    }
}

export type TicketCapabilities = {
    logo: boolean
    business_name: boolean
    phone: boolean
    email: boolean

    branch_name: boolean
    address: boolean

    cashier: boolean
    folio: boolean
    datetime: boolean
    order_type: boolean

    unit_price: boolean
    tax_breakdown: boolean
    payment_method: boolean
    cash_received: boolean
    change: boolean

    rfc: boolean
    invoice_qr: boolean

    custom_texts: boolean
    printing: boolean
}

export type TicketSettingsMeta = {
    invoice_qr: {
        ready: boolean
        enabled: boolean
        requested: boolean
        code: string
        message: string
        fiscal_ready: boolean
        branches_ready: boolean
        fiscal_url: string
        branches_url: string
        missing_branches: { id: string; name: string; missing_fields: string[] }[]
    }
    configuration_ready: boolean

    missing_business_fields:
    string[]

    capabilities:
    TicketCapabilities

    sources: {
        business_ready: boolean
        branch_ready: boolean
        fiscal_ready: boolean
    }

    business: {
        name: string
        phone: string | null
        public_email: string | null
        logo_url: string | null
    }
}

export type TicketSettingsResponse = {
    data: TicketSettings
    meta: TicketSettingsMeta
}

export type UpdateTicketSettingsPayload = {
    folio_prefix: string

    header_text: string | null
    footer_text: string | null
    additional_text: string | null

    show_logo: boolean
    show_business_name: boolean
    show_branch_name: boolean
    show_address: boolean
    show_phone: boolean
    show_email: boolean
    show_rfc: boolean
    show_cashier: boolean
    show_folio: boolean
    show_datetime: boolean
    show_order_type: boolean
    show_unit_price: boolean
    show_tax_breakdown: boolean
    show_payment_method: boolean
    show_cash_received: boolean
    show_change: boolean

    show_invoice_qr: boolean
    invoice_qr_text: string | null

    paper_width_mm: 58 | 80
    auto_print: boolean
    copies: number
}
