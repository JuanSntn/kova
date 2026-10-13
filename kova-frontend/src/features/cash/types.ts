export type CashContextKey = { tenant: string; branch: string; membership: string }
export type CashRegister = { id: string; name: string; is_active: boolean; busy: boolean }
export type CashMovement = { id: string; type: 'IN' | 'OUT'; amount: string; reason: string; author: string; created_at: string }
export type CashShift = {
  id: string; register: { id: string; name: string }; branch: { id: string; name: string }
  responsible: string; is_own: boolean; status: 'OPEN' | 'CLOSED'; business_date: string; opened_timezone: string
  opening_cash: string; opened_at: string; closed_at: string | null; sales_count: number
  expected_cash?: string; counted_cash?: string; difference?: string; difference_reason?: string | null
  cash_sales_total?: string; cash_in_total?: string; cash_out_total?: string; cash_refunds_total?: string
  card_sales_total?: string; transfer_sales_total?: string; closed_by?: string; intervention_reason?: string | null
  movements: CashMovement[]
}
export type CashCount = { id: string; shift_id: string; expected_cash: string; counted_cash: string; difference: string; activity_version: number }
export type CashContext = {
  feature_enabled: boolean; read_only: boolean; shift_required: boolean; closure_required: boolean; can_checkout: boolean
  current_shift: CashShift | null
  available_actions: { open: boolean; move: boolean; close: boolean; supervise_move: boolean; manage_registers: boolean }
  permissions: { view: boolean; operate: boolean; manage: boolean }
}
export type CashPage<T> = { data: T[]; meta: { page: number; last_page: number; total: number } }
