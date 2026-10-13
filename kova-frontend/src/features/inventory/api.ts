import { api } from '@/lib/api'
import type { Supply } from './types'

export type SupplyList = {
  data: Supply[]
  meta: { page: number; last_page: number; total: number; can_manage: boolean }
  taxes: { id: string; name: string }[]
}
export async function fetchSupplies(tenantId: string, search: string, page: number) {
  const { data } = await api.get<SupplyList>('/api/inventory/supplies', { headers: { 'X-Tenant-ID': tenantId }, params: { search, page } })
  return data
}
export async function saveSupply(tenantId: string, supply: Supply, editing: boolean) {
  const { id, version, presentations, ...fields } = supply
  const payload = {
    ...fields,
    ...(editing ? { version } : { id }),
    presentations: presentations.map(({ id, name, quantity, unit, supplier, supplierCode, price, tax }) => ({ id, name, quantity, unit, supplier, supplierCode, price, tax })),
  }
  const { data } = await api.request<{ data: Supply }>({
    url: '/api/inventory/supplies' + (editing ? '/' + id : ''),
    method: editing ? 'PUT' : 'POST',
    headers: { 'X-Tenant-ID': tenantId },
    data: payload,
  })
  return data.data
}
