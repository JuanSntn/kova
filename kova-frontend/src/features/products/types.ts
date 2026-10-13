export type Category = {
    id: string
    name: string
    sort_order: number
    is_active: boolean
    billing_enabled: boolean
    locked_by_plan: boolean
    can_edit: boolean
    can_delete: boolean
    can_use: boolean
    lock_reason: string | null
}

export type ProductCategory = {
    id: string
    name: string
    billing_enabled: boolean
    locked_by_plan: boolean
}

export type Product = {
    id: string
    name: string
    description: string | null
    price: string
    image_path: string | null
    sort_order: number
    is_active: boolean
    preparation_station: 'kitchen' | 'bar' | 'none'
    billing_enabled: boolean
    locked_by_plan: boolean
    can_edit: boolean
    can_delete: boolean
    can_use: boolean
    lock_reason: string | null
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
    fiscal: {
        product_service_code: string | null
        unit_code: string | null
        unit_name: string | null
        tax_object_code: string | null
    }
}

/*
|--------------------------------------------------------------------------
| Product limits / usage
|--------------------------------------------------------------------------
*/

export type CatalogUsage = {
    used: number
    limit: number | null
    remaining: number | null
    is_unlimited: boolean
    limit_reached: boolean
}

export type ProductUsage = CatalogUsage

export type CategoryUsage = CatalogUsage

export type ProductPlan = {
    code: string
    name: string
}

export type ProductsResponse = {
    data: Product[]

    meta?: {
        plan: ProductPlan
        usage: ProductUsage
        category_usage?: CategoryUsage
    }
}

export type CategoriesResponse = {
    data: Category[]

    meta?: {
        plan: ProductPlan
        usage: CategoryUsage
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
    preparation_station: 'kitchen' | 'bar' | 'none'
    sat_product_service_code?: string | null
    sat_unit_code?: string | null
    sat_tax_object_code?: string | null
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
