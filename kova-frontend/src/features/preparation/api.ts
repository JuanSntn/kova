import axios from 'axios'
import { api } from '@/lib/api'

export type PreparationItem = {
  id: string
  name: string
  quantity: number
  modifiers: Array<{ name?: string; option?: string }>
  station: 'kitchen' | 'bar'
  status: 'pending' | 'preparing'
  started_at: string | null
}

export type PreparationOrder = {
  id: string
  folio: string
  created_at: string
  created_by: string | null
  items: PreparationItem[]
}

export type PreparationResponse = {
  data: PreparationOrder[]
  meta: {
    mode: 'shared' | 'independent'
    station: 'kitchen' | 'bar' | null
    station_locked: boolean
  }
}

export async function getPreparationOrders(station?: 'kitchen' | 'bar') {
  return (await api.get<PreparationResponse>('/api/preparation/orders', {
    params: station ? { station } : undefined,
  })).data
}

export async function updatePreparationItem(id: string, status: 'preparing' | 'ready') {
  return (await api.patch<{ data: PreparationItem }>(`/api/preparation/items/${id}`, { status })).data.data
}

export function preparationError(error: unknown) {
  if (axios.isAxiosError<{ message?: string; errors?: Record<string, string[]> }>(error)) {
    return Object.values(error.response?.data?.errors ?? {}).flat()[0]
      ?? error.response?.data?.message
      ?? 'No pudimos actualizar la comanda.'
  }
  return 'No pudimos conectar con el servidor.'
}
