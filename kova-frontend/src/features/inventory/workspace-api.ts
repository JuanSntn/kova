import { api } from '@/lib/api'
import type { Supply, Unit } from './types'

export type RecipeType = 'recipe' | 'production' | 'subrecipe'
export type Component = { type: 'supply' | 'subrecipe'; id: string; quantity: string; unit: Unit }
export type Recipe = { id?: string; version?: number; name: string; type: RecipeType; yield_quantity: string; yield_unit: Unit; output_supply_id: string | null; components: Component[] }
export type Catalog = { categories: { id: string; name: string }[]; supplies: Supply[]; recipes: (Recipe & { id: string })[]; can_manage: boolean }
export type Movement = { id: string; type: string; reason: string; author?: string; created_at: string; lines: { name: string; supply_id: string; unit: Unit; quantity: string; balance_after: string; negative: boolean }[] }
export type Balance = { id: string; name: string; unit: Unit; quantity: string; minimum: string | null; ideal: string | null; negative: boolean; below_minimum: boolean }
export type PageData<T> = { data: T[]; meta: { page: number; last_page: number } }
export const headers = (tenant: string, branch?: string | null) => ({ 'X-Tenant-ID': tenant, ...(branch ? { 'X-Branch-ID': branch } : {}) })
export async function catalog(tenant: string) { return (await api.get<Catalog>('/api/inventory/catalog', { headers: headers(tenant) })).data }
export async function mutation(tenant: string, path: string, data: unknown, method = 'POST', branch?: string | null, key?: string) {
  return (await api.request({ url: '/api/inventory/' + path, method, data, headers: { ...headers(tenant, branch), ...(key ? { 'Idempotency-Key': key } : {}) } })).data
}
export async function downloadExcel(tenant: string, template: boolean) {
  const response = await api.get('/api/inventory/excel', { headers: headers(tenant), params: { template: template ? 1 : 0 }, responseType: 'blob' })
  const url = URL.createObjectURL(response.data)
  const a = document.createElement('a'); a.href = url; a.download = template ? 'plantilla-insumos.xlsx' : 'insumos.xlsx'; a.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
