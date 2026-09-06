import { api } from '@/lib/api'

export type PlanCode = 'free' | 'business' | 'restaurant'

export type BillingPlan = {
  code: PlanCode
  name: string
  monthly_price: string
  max_users: number | null
  max_products: number | null
  monthly_sales_limit: string | null
  included_branches: number
  included_stamps: number
  additional_stamp_price: string | null
  additional_branch_price: string | null
  allows_table_orders: boolean
  allows_cash_shifts: boolean
  allows_inventory: boolean
  allows_invoicing: boolean
}

export type SubscriptionStatus =
  | 'trial'
  | 'active'
  | 'past_due'
  | 'cancelled'

export type PaymentMethod = {
  brand: string | null
  last4: string | null
  exp_month: number | null
  exp_year: number | null
}

export type BillingAddress = {
  line1: string | null
  line2: string | null
  city: string | null
  state: string | null
  postal_code: string | null
  country: string | null
}

export type BillingDetails = {
  name: string | null
  email: string | null
  phone: string | null
  address: BillingAddress | null
}

/*
 * Estados reales que puede devolver Stripe
 * para una factura.
 */
export type InvoiceStatus =
  | 'draft'
  | 'open'
  | 'paid'
  | 'void'
  | 'uncollectible'

export type Invoice = {
  id: string

  /*
   * Puede ser null mientras la factura
   * todavía está en borrador.
   */
  number: string | null

  date: string | null

  plan_name: string

  /*
   * Importes ya convertidos por Laravel
   * de centavos a MXN.
   */
  total: number
  amount_due: number
  amount_paid: number
  amount_remaining: number

  currency: string

  /*
   * Estado original de Stripe.
   */
  status: InvoiceStatus

  /*
   * Etiqueta amigable generada por backend:
   *
   * Pagada
   * Pendiente de pago
   * Crédito pendiente
   * Sin cargo
   * etc.
   */
  status_label: string

  billing_reason: string | null

  url: string | null
  pdf: string | null
}

/*
 * Respuesta del endpoint:
 *
 * GET /api/billing/invoices
 */
export type BillingInvoicesResponse = {
  data: Invoice[]

  pagination: {
    limit: number
    has_more: boolean
    next_cursor: string | null
  }
}

export type BillingSummary = {
  current_plan: PlanCode

  current_plan_name: string

  current_subscription: {
    plan: PlanCode
    plan_name: string
    plan_price: string
    status: SubscriptionStatus
    current_period_ends_at: string | null
    cancel_at_period_end: boolean
    past_due: boolean
    ended_at: string | null
  } | null

  payment_method: PaymentMethod | null

  billing_details: BillingDetails | null

  actions: {
    can_manage_portal: boolean
  }

  /*
   * Ya NO tenemos:
   *
   * recent_invoices
   *
   * porque ahora las facturas tienen
   * su propio endpoint.
   */

  stripe: {
    customer_exists: boolean
    subscription_exists: boolean
  }

  plans: BillingPlan[]
}

/*
|--------------------------------------------------------------------------
| Billing summary
|--------------------------------------------------------------------------
|
| GET /api/billing
|
| Plan actual, suscripción, método de pago,
| información fiscal y planes.
|
*/
export async function getBillingSummary() {
  const response =
    await api.get<BillingSummary>(
      '/api/billing',
    )

  return response.data
}

/*
|--------------------------------------------------------------------------
| Billing invoices
|--------------------------------------------------------------------------
|
| Primera carga:
|
| GET /api/billing/invoices?limit=5
|
| Cargar más:
|
| GET /api/billing/invoices
|     ?limit=5
|     &cursor=in_xxx
|
*/
export async function getBillingInvoices({
  limit = 5,
  cursor = null,
}: {
  limit?: number
  cursor?: string | null
} = {}) {
  const response =
    await api.get<BillingInvoicesResponse>(
      '/api/billing/invoices',
      {
        params: {
          limit,

          /*
           * Axios normalmente omite null,
           * por lo que en la primera petición
           * solamente enviaremos limit=5.
           */
          cursor: cursor ?? undefined,
        },
      },
    )

  return response.data
}

/*
|--------------------------------------------------------------------------
| Checkout
|--------------------------------------------------------------------------
*/
export async function createCheckout(
  plan: Exclude<PlanCode, 'free'>,
) {
  const response = await api.post<{
    url: string
  }>(
    '/api/billing/checkout',
    {
      plan,
    },
  )

  return response.data
}

/*
|--------------------------------------------------------------------------
| Stripe billing portal
|--------------------------------------------------------------------------
*/
export async function createBillingPortal() {
  const response = await api.post<{
    url: string
  }>(
    '/api/billing/portal',
  )

  return response.data
}

/*
|--------------------------------------------------------------------------
| Cancel subscription
|--------------------------------------------------------------------------
*/
export async function cancelSubscription() {
  const response = await api.post<{
    message: string
  }>(
    '/api/billing/cancel',
  )

  return response.data
}

/*
|--------------------------------------------------------------------------
| Reactivate subscription
|--------------------------------------------------------------------------
*/
export async function reactivateSubscription() {
  const response = await api.post<{
    message: string
  }>(
    '/api/billing/reactivate',
  )

  return response.data
}