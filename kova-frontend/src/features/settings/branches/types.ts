export type Branch = {
  id: string
  code: string
  name: string
  postalCode: string | null
  state: string | null
  municipality: string | null
  locality: string | null
  city: string | null
  neighborhood: string | null
  street: string | null
  exteriorNumber: string | null
  interiorNumber: string | null
  countryCode: string | null
  timezone: string | null
  latitude: string | null
  longitude: string | null
  phone: string | null
  isActive: boolean
  billingEnabled: boolean
  fiscalReady: boolean
}

export type BranchCapacity = {
  limit: number | null
  used: number
  remaining: number | null
  unlimited: boolean
  canCreate: boolean
  reason:
  | 'contract_missing'
  | 'subscription_read_only'
  | 'limit_reached'
  | null
}

export type BranchCapabilities = {
  allowsInvoicing: boolean
}

export type AdditionalBranchPurchase = {
  available: boolean
  canPurchase: boolean
  price: {
    amount: number
    currency: string
    interval: 'month'
  } | null
  pending: {
    status: string
    invoiceUrl: string | null
  } | null
  active: {
    quantity: number
    amount: number
    currency: string
    interval: 'month'
    nextBillingAt: string | null
    requiredRetainedBranches: number
    effectiveAt: string | null
  } | null
  canCancel: boolean
}

export type BranchPayload = {
  code: string
  name: string
  postal_code: string
  city: string
  neighborhood: string
  street: string
  exterior_number: string
  interior_number: string | null
  phone: string | null
  is_active: boolean
}

export type BranchFiscalSeriesOption = {
  series: string
  next_folio: number
  next_number: string
  is_default: boolean
}

export type BranchFiscalSettings = {
  mode: 'inherit' | 'override'
  default_series: string
  effective_series: string
  effective_next_folio: number
  effective_next_number: string
  series: BranchFiscalSeriesOption[]
}

export type BranchFiscalUpdate =
  | { mode: 'inherit' }
  | { mode: 'override'; series: string }
