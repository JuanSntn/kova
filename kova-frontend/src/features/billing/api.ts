import { api } from '@/lib/api'

export type PlanCode = string

export type BillingInterval =
  | 'month'
  | 'year'

export type OfferPrice = {
  offer_id: string
  interval: BillingInterval
  amount: number
  currency: string
  available: boolean
}

export type AddonOffer = {
  version: string
  code: string
  name: string
  mode: 'recurring' | 'metered'
  automatic: boolean
  prices: OfferPrice[]
}

export type SubscriptionAddon = {
  id: string
  code: string
  name: string
  offer_id: string
  mode: 'recurring' | 'metered'
  quantity: number
  status: string
  amount: number
  currency: string
  interval: BillingInterval
  ends_at: string | null
}

export type PendingChange = {
  confirmed: boolean
  payment_status: string | null
  invoice_url: string | null
  id: string
  kind: string
  status: string
  change_mode: 'immediate' | 'period_end'
  effective_at: string | null
  target_name: string | null
  quantity: number | null
  target_amount: number | null
  target_interval:
  BillingInterval | null
  target_currency: string | null
  retained_branch_ids: string[]
  retained_membership_ids: string[]
}

export type BillingPlan = {
  version: string

  prices: OfferPrice[]

  addons: AddonOffer[]

  code: PlanCode

  name: string

  /*
   * Jerarquía comercial.
   *
   * Free       0
   * Business   100
   * Restaurant 200
   */
  tier: number

  monthly_price: string

  max_users: number | null

  max_products: number | null

  max_categories: number | null

  monthly_sales_limit:
  string | null

  included_branches:
  number | null

  included_stamps:
  number | null

  additional_stamp_price:
  string | null

  additional_branch_price:
  string | null

  allows_counter_orders: boolean

  allows_table_orders: boolean

  allows_cash_shifts: boolean

  allows_preparation: boolean

  max_preparation_stations: number | null

  allows_inventory: boolean

  allows_invoicing: boolean
}

export type SubscriptionStatus =
  | 'pending'
  | 'suspended'
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
|--------------------------------------------------------------------------
| Invoice
|--------------------------------------------------------------------------
*/

export type InvoiceStatus =
  | 'draft'
  | 'open'
  | 'paid'
  | 'void'
  | 'uncollectible'

export type Invoice = {
  id: string

  number: string | null

  date: string | null

  plan_name: string

  /*
   * El backend ya entrega importes
   * convertidos a MXN.
   */
  total: number

  amount_due: number

  amount_paid: number

  amount_remaining: number

  currency: string

  status: InvoiceStatus

  status_label: string

  billing_reason: string | null

  url: string | null

  pdf: string | null
}

export type BillingInvoicesResponse = {
  data: Invoice[]

  pagination: {
    limit: number

    has_more: boolean

    next_cursor:
    string | null
  }
}

/*
|--------------------------------------------------------------------------
| Billing summary
|--------------------------------------------------------------------------
*/

export type BillingSummary = {
  stamp_usage: StampUsage
  capacity_usage:
  Record<string, number>

  monthly_sales_consumed:
  number

  contract: {
    id: string

    version: string

    offer_id: string

    amount: number

    currency: string

    interval:
    BillingInterval

    status:
    SubscriptionStatus
  } | null

  entitlements:
  Record<
    string,
    number | boolean | null
  >

  usage_period: {
    starts_at: string

    ends_at: string
  } | null

  usage: {
    feature: string

    consumed: number

    included:
    number | null

    starts_at: string

    ends_at: string
  }[]

  addons:
  SubscriptionAddon[]

  available_addons:
  AddonOffer[]

  branches: {
    id: string

    name: string
  }[]

  active_memberships: {
    id: string
    name: string
    username: string | null
    is_owner: boolean
  }[]

  billing_components: {
    role: string

    status: string

    next_billing_at:
    string | null
  }[]

  pending_changes:
  PendingChange[]

  pending_addon_operations: {
    status: string

    quantity: number

    name: string | null

    invoice_url:
    string | null
  }[]

  pending_contracts: {
    name: string

    status: string

    plan: string

    offer_id: string

    interval:
    BillingInterval

    checkout_status:
    string | null

    payment_status:
    string | null

    state:
    | 'awaiting_customer'
    | 'awaiting_payment'
    | 'provisioning'
    | 'reconciliation_required'

    can_resume: boolean

    can_cancel: boolean

    message: string
  }[]

  current_plan:
  PlanCode

  current_plan_name:
  string

  current_subscription: {
    interval:
    BillingInterval

    amount: number

    currency: string

    grace_ends_at:
    string | null

    version: string

    offer_id: string

    plan:
    PlanCode

    plan_name:
    string

    plan_price:
    string

    status:
    SubscriptionStatus

    current_period_ends_at:
    string | null

    cancel_at_period_end:
    boolean

    past_due:
    boolean

    ended_at:
    string | null
  } | null

  payment_method:
  PaymentMethod | null

  billing_details:
  BillingDetails | null

  actions: {
    can_manage: boolean

    can_manage_portal: boolean
  }

  stripe: {
    customer_exists: boolean

    subscription_exists: boolean
  }

  plans:
  BillingPlan[]
}

export type StampUsage = {
  used: number
  included: number | null
  available: number | null
  overages: number
  estimated_total: number
  currency: string
  unit_amount: number | null
  estimated_unit_total: number | null
  overage_enabled: boolean
  can_enable_overage: boolean
  period_starts_at: string | null
  period_ends_at: string | null
  metering: { pending: number; reported: number; failed: number }
}

/*
|--------------------------------------------------------------------------
| Billing summary
|--------------------------------------------------------------------------
*/

export async function getBillingSummary() {
  const response =
    await api.get<BillingSummary>(
      '/api/billing',
    )

  return response.data
}

export async function updateStampOverage(enabled: boolean) {
  return (await api.put<StampUsage>('/api/billing/stamp-overage', { enabled })).data
}

/*
|--------------------------------------------------------------------------
| Billing invoices
|--------------------------------------------------------------------------
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

          cursor:
            cursor
            ?? undefined,
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
  plan: string,
  interval:
    BillingInterval = 'month',
  offer_id?: string,
  retained_branch_ids:
    string[] = [],
  retained_membership_ids:
    string[] = [],
  operationId:
    string = crypto.randomUUID(),
) {
  const {
    data,
  } =
    await api.post<{
      url: string
    }>(
      '/api/billing/checkout',
      {
        plan,

        interval,

        offer_id,

        retained_branch_ids,
        retained_membership_ids,
      },
      {
        headers: {
          'Idempotency-Key':
            operationId,
        },
      },
    )

  return data
}

export async function reconcileBilling() {
  const response =
    await api.post<{
      status: 'reconciled' | 'reconciliation_required'
    }>(
      '/api/billing/reconcile',
    )

  return response.data
}

export async function cancelPendingCheckout() {
  const response =
    await api.post<{
      state: string
    }>(
      '/api/billing/checkout/cancel-pending',
    )

  return response.data
}

/*
|--------------------------------------------------------------------------
| Offers
|--------------------------------------------------------------------------
*/

export async function getBillingOffers() {
  return (
    await api.get<{
      data: BillingPlan[]
    }>(
      '/api/billing/offers',
    )
  ).data.data
}

/*
|--------------------------------------------------------------------------
| Pending changes
|--------------------------------------------------------------------------
*/

export async function cancelPendingChange(
  id: string,
) {
  return (
    await api.delete(
      `/api/billing/changes/${id}`,
    )
  ).data
}

/*
|--------------------------------------------------------------------------
| Addons
|--------------------------------------------------------------------------
*/

export async function addSubscriptionAddon(
  offer_id: string,
  quantity: number,
  operationId: string,
) {
  return (
    await api.post<{
      status: string

      invoice_url?:
      string | null
    }>(
      '/api/billing/addons',
      {
        offer_id,

        quantity,
      },
      {
        headers: {
          'Idempotency-Key':
            operationId,
        },
      },
    )
  ).data
}

export async function reduceSubscriptionAddon(
  id: string,
  quantity: number,
  retained_branch_ids:
    string[],
) {
  return (
    await api.patch(
      `/api/billing/addons/${id}`,
      {
        quantity,

        retained_branch_ids,
      },
    )
  ).data
}

/*
|--------------------------------------------------------------------------
| Stripe billing portal
|--------------------------------------------------------------------------
*/

export async function createBillingPortal() {
  const response =
    await api.post<{
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

export async function cancelSubscription(
  retained_branch_ids:
    string[] = [],
  retained_membership_ids:
    string[] = [],
) {
  const response =
    await api.post<{
      message: string
    }>(
      '/api/billing/cancel',
      {
        retained_branch_ids,
        retained_membership_ids,
      },
    )

  return response.data
}

/*
|--------------------------------------------------------------------------
| Reactivate subscription
|--------------------------------------------------------------------------
*/

export async function reactivateSubscription() {
  const response =
    await api.post<{
      message: string
    }>(
      '/api/billing/reactivate',
    )

  return response.data
}
