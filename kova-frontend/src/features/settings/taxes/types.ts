export type TaxFactorType =
    | 'rate'
    | 'exempt'

export type Tax = {
    id: string

    name: string

    code: string

    factor_type:
    TaxFactorType

    rate:
    | string
    | number

    is_active: boolean
}

export type TaxConfig = {
    default_tax:
    Tax

    prices_include_tax:
    boolean
}

export type TaxUpdatePayload = {
    is_active: boolean
}
