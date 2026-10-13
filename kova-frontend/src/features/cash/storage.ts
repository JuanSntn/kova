import type { CashContextKey, CashCount, CashShift } from './types'
const KEY = 'kova:cash:operation:v1'
export type CashAttempt = {
  version: 1; context: CashContextKey; key: string; path: string; payload: Record<string, unknown>
  result?: CashCount | CashShift | Record<string, unknown>
}
export function readCashAttempt(): CashAttempt | null {
  const raw = sessionStorage.getItem(KEY)
  if (!raw) return null
  const value = JSON.parse(raw) as CashAttempt
  if (value.version !== 1 || !value.context?.tenant || !value.context.branch || !value.context.membership || !value.key
    || !/^\/api\/cash\/shifts(?:\/[\w-]+\/(?:movements|counts|close))?$/.test(value.path)
    || !value.payload || typeof value.payload !== 'object') throw new Error('No se puede leer la operación de caja guardada. Recupera el almacenamiento antes de continuar.')
  return value
}
export function saveCashAttempt(attempt: CashAttempt) {
  const json = JSON.stringify(attempt)
  sessionStorage.setItem(KEY, json)
  if (sessionStorage.getItem(KEY) !== json) throw new Error('No se pudo guardar el intento de caja.')
}
export function clearCashAttempt() { sessionStorage.removeItem(KEY) }
export function cashContextLocked() {
  try { const saved = readCashAttempt(); return Boolean(saved && !saved.result) } catch { return true }
}
export function pendingCashContext() {
  try { const saved = readCashAttempt(); return saved && !saved.result ? saved.context : null } catch { return null }
}
