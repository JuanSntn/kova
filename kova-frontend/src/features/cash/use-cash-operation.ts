import { useRef, useState } from 'react'
import { isAxiosError } from 'axios'
import { useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { cashHeaders } from './api'
import { clearCashAttempt, readCashAttempt, saveCashAttempt, type CashAttempt } from './storage'
import type { CashContextKey } from './types'

export type CashOutcome = { ok: true; key: string; data: NonNullable<CashAttempt['result']> } | { ok: false; code?: string }

export function useCashOperation(context: CashContextKey) {
  const cache = useQueryClient()
  const [initial] = useState(() => {
    try { return { attempt: readCashAttempt(), error: '' } }
    catch (e) { return { attempt: null, error: e instanceof Error ? e.message : 'Almacenamiento no disponible.' } }
  })
  const [attempt, setAttempt] = useState<CashAttempt | null>(initial.attempt)
  const [confirmedAttempt, setConfirmedAttempt] = useState<CashAttempt | null>(initial.attempt?.result ? initial.attempt : null)
  const [error, setError] = useState(initial.error)
  const [busy, setBusy] = useState(false)
  const ref = useRef(false)
  const sameContext = !attempt || Object.keys(context).every(k => context[k as keyof CashContextKey] === attempt.context[k as keyof CashContextKey])
  const matches = sameContext || Boolean(attempt?.result)
  async function send(saved: CashAttempt): Promise<CashOutcome> {
    if (ref.current || !matches || initial.error) return { ok: false }
    ref.current = true; setBusy(true); setError('')
    try {
      const response = await api.post(saved.path, saved.payload, { headers: { ...cashHeaders(saved.context), 'Idempotency-Key': saved.key } })
      const completed = { ...saved, result: response.data.data }
      saveCashAttempt(completed); setAttempt(completed); setConfirmedAttempt(completed)
      await cache.invalidateQueries({ queryKey: ['cash'] })
      return { ok: true, key: saved.key, data: completed.result }
    } catch (e) {
      const status = isAxiosError(e) ? e.response?.status : null
      const code = isAxiosError(e) ? e.response?.data?.code : null
      // These responses prove that this mutation did not commit. Conflict of keys is uncertain.
      if (status === 422 || (status === 409 && ['CASH_COUNT_STALE','CASH_SHIFT_CLOSED','CASH_REGISTER_BUSY','CASH_USER_ALREADY_HAS_SHIFT','CASH_INSUFFICIENT_FUNDS','CASH_FEATURE_UNAVAILABLE','CASH_DIFFERENCE_REASON_REQUIRED'].includes(code))) {
        try { clearCashAttempt(); setAttempt(null); setConfirmedAttempt(null) } catch { /* Preserve unresolved intent. */ }
      }
      setError(isAxiosError(e) ? e.response?.data?.message ?? 'No conocemos el resultado. Reenvía el mismo intento para verificarlo.' : 'No se pudo guardar el resultado. Reenvía el mismo intento para recuperarlo.')
      return { ok: false, code }
    } finally { ref.current = false; setBusy(false) }
  }
  async function submit(path: string, payload: Record<string, unknown>): Promise<CashOutcome> {
    if (ref.current || initial.error || !matches || (attempt && !attempt.result)) return { ok: false }
    const saved: CashAttempt = { version: 1, context, key: crypto.randomUUID(), path, payload }
    try { saveCashAttempt(saved); setAttempt(saved) }
    catch { setError('No se puede guardar el intento. Habilita el almacenamiento antes de continuar.'); return { ok: false } }
    return send(saved)
  }
  return { attempt: sameContext ? attempt : null, busy, blocked: busy || Boolean(attempt && !attempt.result) || !matches || Boolean(initial.error),
    confirmedAttempt: confirmedAttempt && Object.keys(context).every(k => context[k as keyof CashContextKey] === confirmedAttempt.context[k as keyof CashContextKey]) ? confirmedAttempt : null,
    error: !matches ? 'Hay una operación guardada de otro usuario o sucursal. Regresa a su contexto para recuperarla.' : error,
    finish: () => { if (ref.current || (attempt && !attempt.result)) return; clearCashAttempt(); setAttempt(null); setConfirmedAttempt(null); setError('') },
    submit, retry: () => attempt ? send(attempt) : Promise.resolve<CashOutcome>({ ok: false }) }
}
