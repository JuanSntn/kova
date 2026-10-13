import { cashContextLocked, pendingCashContext } from '@/features/cash/storage'
import type { CheckoutContext, CreatePosOrderPayload, PosCreatedOrder, PosPaymentMethod } from './types'

const KEY = 'kova:pos:checkout:v1'
export type CheckoutDraft = {
  version: 1
  context: CheckoutContext
  items: CreatePosOrderPayload['items']
  paymentMethod: PosPaymentMethod
  intent?: { key: string; payload: CreatePosOrderPayload }
  order?: PosCreatedOrder
}
let preparing = false
export function setCheckoutPreparing(value: boolean) { preparing = value }
export function sameContext(a: CheckoutContext, b: CheckoutContext) {
  return a.tenant === b.tenant && a.branch === b.branch && a.membership === b.membership
}
export function readCheckout(): CheckoutDraft | null {
  const raw = sessionStorage.getItem(KEY)
  if (!raw) return null
  const value = JSON.parse(raw) as CheckoutDraft
  if (value.version !== 1 || !value.context?.tenant || !value.context.branch || !value.context.membership
    || !Array.isArray(value.items) || !['cash', 'card', 'transfer'].includes(value.paymentMethod)
    || !value.items.every(item => typeof item.product_id === 'string' && Number.isInteger(item.quantity) && item.quantity >= 1 && item.quantity <= 99)
    || !value.items.every(item => item.modifiers === undefined || (Array.isArray(item.modifiers) && item.modifiers.length <= 100 && item.modifiers.every(id => typeof id === 'string')))
    || (value.intent && (!value.intent.key || !value.intent.payload?.quote_token || !Array.isArray(value.intent.payload.items)))
    || (value.order && (!value.intent || !value.order.id || !Array.isArray(value.order.items)))) {
    throw new Error('No se puede leer el intento guardado. No inicies otro cobro; recupera el almacenamiento de esta pestaña.')
  }
  return value
}
export function saveCheckout(draft: CheckoutDraft) {
  const json = JSON.stringify(draft)
  sessionStorage.setItem(KEY, json)
  if (sessionStorage.getItem(KEY) !== json) throw new Error('No se pudo guardar el cobro en esta pestaña.')
}
export function checkoutContextLocked(): boolean { return cashContextLocked() || posCheckoutLocked() }
export function posCheckoutLocked(): boolean {
  if (preparing) return true
  try {
    const draft = readCheckout()
    return Boolean(draft?.intent && !draft.order)
  } catch { return true }
}
export function pendingCheckoutContext(): CheckoutContext | null {
  try {
    const draft = readCheckout()
    return draft?.intent && !draft.order ? draft.context : pendingCashContext()
  } catch { return null }
}
