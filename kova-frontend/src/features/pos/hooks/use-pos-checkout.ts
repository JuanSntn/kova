import { useCallback, useRef, useState } from 'react'
import { isAxiosError } from 'axios'
import { createPosOrder, quotePosOrder, recoverPosOrder } from '../api'
import { readCheckout, sameContext, saveCheckout, setCheckoutPreparing, type CheckoutDraft } from '../checkout-storage'
import type { CheckoutContext, CheckoutQuote, CreatePosOrderPayload, PosCartItem, PosCreatedOrder, PosPaymentMethod } from '../types'

type Phase = 'editing' | 'preparing' | 'ready' | 'sending' | 'uncertain' | 'complete'
export function usePosCheckout(context: CheckoutContext) {
  const [initial] = useState(() => {
    try {
      const saved = readCheckout()
      if (saved && !sameContext(saved.context, context) && saved.intent && !saved.order) {
        throw new Error('Hay una venta pendiente en otro contexto. Regresa al negocio, usuario y sucursal originales para verificarla.')
      }
      return { draft: saved && sameContext(saved.context, context) ? saved : null, error: '' }
    } catch (error) { return { draft: null, error: error instanceof Error ? error.message : 'Almacenamiento no disponible.' } }
  })
  const draft = useRef<CheckoutDraft>(initial.draft ?? { version: 1, context, items: [], paymentMethod: 'cash' })
  const busy = useRef(false)
  const [phase, setPhase] = useState<Phase>(initial.draft?.order ? 'complete' : initial.draft?.intent ? 'uncertain' : 'editing')
  const [quote, setQuote] = useState<CheckoutQuote | null>(null)
  const [order, setOrder] = useState<PosCreatedOrder | null>(initial.draft?.order ?? null)
  const [message, setMessage] = useState(initial.error)
  const [storageError, setStorageError] = useState(initial.error)
  const persist = useCallback((value: CheckoutDraft) => {
    try { saveCheckout(value); draft.current = value; setStorageError(''); return true }
    catch { setStorageError('No se pudo guardar el estado de la venta. Habilita el almacenamiento de esta pestaña y vuelve a verificar.'); return false }
  }, [])
  const saveCart = useCallback((items: PosCartItem[], paymentMethod: PosPaymentMethod) => {
    if (initial.error || draft.current.intent || busy.current) return
    persist({ version: 1, context, items: items.map(({ product_id, quantity, modifiers }) => ({ product_id, quantity, modifiers: modifiers.map(m => m.modifier_option_id).sort() })), paymentMethod })
  }, [context, initial.error, persist])
  async function prepare(items: PosCartItem[], paymentMethod: PosPaymentMethod) {
    if (busy.current || draft.current.intent || initial.error) return
    const next: CheckoutDraft = { version: 1, context, items: items.map(({ product_id, quantity, modifiers }) => ({ product_id, quantity, modifiers: modifiers.map(m => m.modifier_option_id).sort() })), paymentMethod }
    if (!persist(next)) return
    busy.current = true; setCheckoutPreparing(true); setPhase('preparing'); setMessage('')
    try { setQuote(await quotePosOrder(next.items, context)); setPhase('ready') }
    catch (error) { setMessage(errorMessage(error)); setPhase('editing') }
    finally { busy.current = false; setCheckoutPreparing(false) }
  }
  function complete(result: PosCreatedOrder) {
    // Commit the receipt locally BEFORE releasing the intent or clearing the cart.
    if (!persist({ ...draft.current, order: result })) { setPhase('uncertain'); return }
    setOrder(result); setPhase('complete'); setMessage('')
  }
  async function send() {
    if (busy.current || !draft.current.intent) return
    busy.current = true; setPhase('sending'); setCheckoutPreparing(true)
    const intent = draft.current.intent
    try { complete(await createPosOrder(intent.payload, intent.key, draft.current.context)) }
    catch (error) {
      const status = isAxiosError(error) ? error.response?.status : undefined
      const code = isAxiosError(error) ? error.response?.data?.code : undefined
      // Only known domain validation responses prove this POST did not register a sale.
      const definitive = status === 422 || (status === 409 && [
        'POS_QUOTE_CHANGED', 'POS_QUOTE_EXPIRED', 'POS_QUOTE_INVALID',
        'INSUFFICIENT_CASH_RECEIVED', 'MONTHLY_SALES_LIMIT_EXCEEDED',
        'CASH_SHIFT_REQUIRED', 'CASH_SHIFT_CLOSED', 'CASH_QUOTE_CHANGED', 'CASH_SHIFT_CLOSE_REQUIRED',
      ].includes(code))
      if (definitive && persist({ ...draft.current, intent: undefined })) {
        setQuote(null); setPhase('editing')
      } else { setPhase('uncertain') }
      setMessage(errorMessage(error))
    } finally { busy.current = false; setCheckoutPreparing(false) }
  }
  async function confirm(amountReceived?: number) {
    if (busy.current || phase !== 'ready' || !quote || draft.current.intent) return
    const payload: CreatePosOrderPayload = {
      order_type: 'counter', quote_token: quote.quote_token, items: draft.current.items,
      payment_method: draft.current.paymentMethod,
      ...(draft.current.paymentMethod === 'cash' ? { amount_received: amountReceived?.toFixed(2) } : {}),
    }
    if (!persist({ ...draft.current, intent: { key: crypto.randomUUID(), payload } })) return
    await send()
  }
  async function verify() {
    if (busy.current || !draft.current.intent) return
    busy.current = true; setPhase('sending'); setCheckoutPreparing(true)
    try { complete(await recoverPosOrder(draft.current.intent.key, draft.current.context)) }
    catch {
      setPhase('uncertain')
      setMessage('Aún no hay un resultado confirmado. Puedes verificar de nuevo o reenviar exactamente el mismo intento. No vuelvas a cobrar al cliente.')
    } finally { busy.current = false; setCheckoutPreparing(false) }
  }
  function finish() {
    if (busy.current || (draft.current.intent && !draft.current.order)) return false
    if (!persist({ version: 1, context, items: [], paymentMethod: 'cash' })) return false
    setOrder(null); setQuote(null); setPhase('editing'); setMessage(''); return true
  }
  function closePrepared() { if (phase === 'ready') { setQuote(null); setPhase('editing') } }
  return { phase, quote, order, message, storageError, initial: initial.draft, saveCart, prepare, confirm, verify, retry: send, finish, closePrepared }
}
function errorMessage(error: unknown): string {
  if (isAxiosError(error) && error.response?.data?.message) return String(error.response.data.message)
  return 'Se perdió la comunicación. Conservamos el intento para verificar el resultado.'
}
