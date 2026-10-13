import axios from 'axios'

import { api } from '@/lib/api'

import type {
    ApiErrorResponse,
    CategoriesResponse,
    Category,
    CreateCategoryPayload,
    CreateProductPayload,
    Product,
    ProductsResponse,
    UpdateCategoryPayload,
    UpdateProductPayload,
} from './types'

type DataResponse<T> = {
    data: T
}

/*
|--------------------------------------------------------------------------
| Categories
|--------------------------------------------------------------------------
*/

export async function getCategories(): Promise<Category[]> {
    const response =
        await api.get<CategoriesResponse>(
            '/api/pos/categories',
        )

    return response.data.data
}

export async function getCategoriesWithMeta(): Promise<CategoriesResponse> {
    const response =
        await api.get<CategoriesResponse>(
            '/api/pos/categories',
        )

    return response.data
}

export async function createCategory(
    payload: CreateCategoryPayload,
): Promise<Category> {
    const response =
        await api.post<
            DataResponse<Category>
        >(
            '/api/pos/categories',
            payload,
        )

    return response.data.data
}

export async function updateCategory(
    categoryId: string,
    payload: UpdateCategoryPayload,
): Promise<Category> {
    const response =
        await api.put<
            DataResponse<Category>
        >(
            `/api/pos/categories/${categoryId}`,
            payload,
        )

    return response.data.data
}

export async function deleteCategory(
    categoryId: string,
): Promise<void> {
    await api.delete(
        `/api/pos/categories/${categoryId}`,
    )
}

/*
|--------------------------------------------------------------------------
| Products
|--------------------------------------------------------------------------
*/

export async function getProducts(): Promise<ProductsResponse> {
    const response =
        await api.get<ProductsResponse>(
            '/api/pos/products',
        )

    return response.data
}

export async function createProduct(
    payload: CreateProductPayload,
): Promise<Product> {
    const formData =
        toProductFormData(
            payload,
        )

    const response =
        await api.post<
            DataResponse<Product>
        >(
            '/api/pos/products',
            formData,
        )

    return response.data.data
}

export async function updateProduct(
    productId: string,
    payload: UpdateProductPayload,
): Promise<Product> {
    const formData =
        toProductFormData(
            payload,
        )

    /*
     * Para multipart/form-data con PHP/Laravel
     * usamos method spoofing.
     *
     * El request HTTP real es POST,
     * pero Laravel lo interpreta como PUT.
     */
    formData.append(
        '_method',
        'PUT',
    )

    const response =
        await api.post<
            DataResponse<Product>
        >(
            `/api/pos/products/${productId}`,
            formData,
        )

    return response.data.data
}

export async function deleteProduct(
    productId: string,
): Promise<void> {
    await api.delete(
        `/api/pos/products/${productId}`,
    )
}

/*
|--------------------------------------------------------------------------
| Product FormData
|--------------------------------------------------------------------------
*/

function toProductFormData(
    payload:
        | CreateProductPayload
        | UpdateProductPayload,
): FormData {
    const formData =
        new FormData()

    if (
        payload.name !==
        undefined
    ) {
        formData.append(
            'name',
            payload.name,
        )
    }

    if (
        payload.category_id !==
        undefined
    ) {
        formData.append(
            'category_id',
            payload.category_id,
        )
    }

    if (
        payload.price !==
        undefined
    ) {
        formData.append(
            'price',
            String(
                payload.price,
            ),
        )
    }

    if (
        payload.tax_id !==
        undefined
    ) {
        formData.append(
            'tax_id',
            payload.tax_id ?? '',
        )
    }

    if (
        payload.description !==
        undefined
    ) {
        formData.append(
            'description',
            payload.description ?? '',
        )
    }

    /*
     * Solo agregamos image cuando
     * realmente sea un File.
     *
     * Esto evita que Laravel reciba:
     * image = "null"
     * image = "[object Object]"
     * etc.
     */
    if (
        payload.image instanceof File
    ) {
        formData.append(
            'image',
            payload.image,
        )
    }

    if (
        payload.sort_order !==
        undefined
    ) {
        formData.append(
            'sort_order',
            String(
                payload.sort_order,
            ),
        )
    }

    /*
     * FormData convierte todo a texto.
     *
     * Laravel entiende correctamente
     * 1 / 0 con la validación boolean.
     */
    if (
        payload.is_active !==
        undefined
    ) {
        formData.append(
            'is_active',
            payload.is_active
                ? '1'
                : '0',
        )
    }

    if (payload.preparation_station !== undefined) {
        formData.append('preparation_station', payload.preparation_station)
    }

    const fiscalFields = [
        'sat_product_service_code',
        'sat_unit_code',
        'sat_tax_object_code',
    ] as const

    fiscalFields.forEach((field) => {
        if (payload[field] !== undefined) {
            formData.append(field, payload[field] ?? '')
        }
    })

    return formData
}

/*
|--------------------------------------------------------------------------
| Error helpers
|--------------------------------------------------------------------------
*/

export function getApiErrorMessage(
    error: unknown,
): string {
    if (
        !axios.isAxiosError<
            ApiErrorResponse
        >(error)
    ) {
        return 'Ocurrió un error inesperado.'
    }

    const data =
        error.response?.data

    /*
     * Primero buscamos errores de
     * validación Laravel.
     *
     * {
     *   errors: {
     *     image: [
     *       "La imagen..."
     *     ]
     *   }
     * }
     */
    const errors =
        data?.errors

    if (
        errors &&
        typeof errors ===
        'object'
    ) {
        const firstError =
            Object.values(
                errors,
            )[0]

        if (
            Array.isArray(
                firstError,
            ) &&
            typeof firstError[0] ===
            'string'
        ) {
            return firstError[0]
        }
    }

    /*
     * Mensaje principal.
     */
    if (
        typeof data?.message ===
        'string'
    ) {
        return data.message
    }

    return 'Ocurrió un error inesperado.'
}

export function isPlanLimitExceeded(
    error: unknown,
): boolean {
    if (
        !axios.isAxiosError<
            ApiErrorResponse
        >(error)
    ) {
        return false
    }

    return (
        error.response?.data
            ?.code ===
        'PLAN_LIMIT_EXCEEDED'
    )
}
