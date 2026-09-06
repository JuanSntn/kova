export type Category = {
    id: string
    name: string
    sort_order: number
    is_active: boolean
}

export type ProductCategory = {
    id: string
    name: string
}

export type Product = {
    id: string
    name: string
    description: string | null
    price: string
    image_path: string | null
    sort_order: number
    is_active: boolean
    category: ProductCategory
    tax: {
        mode:
        | 'default'
        | 'specific'
        id: string | null
        effective: {
            id: string
            name: string
            code: string
            factor_type:
            | 'rate'
            | 'exempt'
            rate: string
        } | null
    }
}

/*
|--------------------------------------------------------------------------
| Product limits / usage
|--------------------------------------------------------------------------
*/

export type ProductUsage = {
    used: number
    limit: number | null
    remaining: number | null
    is_unlimited: boolean
    limit_reached: boolean
}

export type ProductPlan = {
    code: string
    name: string
}

export type ProductsResponse = {
    data: Product[]

    meta?: {
        plan: ProductPlan
        usage: ProductUsage
    }
}

/*
|--------------------------------------------------------------------------
| Category payloads
|--------------------------------------------------------------------------
*/

export type CreateCategoryPayload = {
    name: string
    sort_order?: number
    is_active?: boolean
}

export type UpdateCategoryPayload =
    Partial<CreateCategoryPayload>

/*
|--------------------------------------------------------------------------
| Product payloads
|--------------------------------------------------------------------------
*/

export type CreateProductPayload = {
    name: string
    category_id: string
    tax_id?: string | null
    price: number

    description?: string | null

    image?: File | null

    sort_order?: number

    is_active: boolean
}


export type UpdateProductPayload =
    Partial<CreateProductPayload>

/*
|--------------------------------------------------------------------------
| API errors
|--------------------------------------------------------------------------
*/

export type ApiErrorResponse = {
    message?: string
    code?: string

    errors?: Record<
        string,
        string[]
    >
}


export type PlanLimitExceededResponse = {
    message: string
    code: 'PLAN_LIMIT_EXCEEDED'
}
