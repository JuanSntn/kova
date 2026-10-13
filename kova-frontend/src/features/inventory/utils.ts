import { isAxiosError } from 'axios'
export function errorMessage(e: unknown) {
  if (!isAxiosError(e)) return 'No se pudo completar la operación.'
  const errors = e.response?.data?.errors as Record<string, string[]> | undefined
  return errors ? Object.values(errors).flat().join(' ') : e.response?.data?.message ?? 'No se confirmó la respuesta. Revisa tu conexión.'
}
export const quantity = (value: string | null) => value === null ? '—' : Number(value).toLocaleString('es-MX', { maximumFractionDigits: 6 })
