import axios from 'axios'

import { api } from '@/lib/api'

import type {
  AdditionalBranchPurchase,
  Branch,
  BranchCapabilities,
  BranchCapacity,
  BranchFiscalSettings,
  BranchFiscalUpdate,
  BranchPayload,
} from './types'

type DataResponse<T> = {
  data: T
}

type AdditionalBranchPurchaseResponse = {
  available: boolean
  can_purchase: boolean
  price: AdditionalBranchPurchase['price']
  pending: {
    status: string
    invoice_url: string | null
  } | null
  active: {
    quantity: number
    amount: number
    currency: string
    interval: 'month'
    next_billing_at: string | null
    required_retained_branches: number
    effective_at: string | null
  } | null
  can_cancel: boolean
}

type ApiErrorResponse = {
  message?: string
  errors?: Record<string, string[]>
}

export async function getBranches(): Promise<{
  branches: Branch[]
  capacity: BranchCapacity
  capabilities: BranchCapabilities
  additionalBranch: AdditionalBranchPurchase
}> {
  const response = await api.get<
    DataResponse<Branch[]> & {
      meta: {
        capacity: BranchCapacity
        capabilities: BranchCapabilities
        additionalBranch: AdditionalBranchPurchaseResponse
      }
    }
  >('/api/branches')

  return {
    branches: response.data.data,
    capacity: response.data.meta.capacity,
    capabilities: response.data.meta.capabilities,
    additionalBranch: {
      available: response.data.meta.additionalBranch.available,
      canPurchase: response.data.meta.additionalBranch.can_purchase,
      price: response.data.meta.additionalBranch.price,
      pending: response.data.meta.additionalBranch.pending
        ? {
          status: response.data.meta.additionalBranch.pending.status,
          invoiceUrl: response.data.meta.additionalBranch.pending.invoice_url,
        }
        : null,
      active: response.data.meta.additionalBranch.active
        ? {
          quantity: response.data.meta.additionalBranch.active.quantity,
          amount: response.data.meta.additionalBranch.active.amount,
          currency: response.data.meta.additionalBranch.active.currency,
          interval: response.data.meta.additionalBranch.active.interval,
          nextBillingAt: response.data.meta.additionalBranch.active.next_billing_at,
          requiredRetainedBranches: response.data.meta.additionalBranch.active.required_retained_branches,
          effectiveAt: response.data.meta.additionalBranch.active.effective_at,
        }
        : null,
      canCancel: response.data.meta.additionalBranch.can_cancel,
    },
  }
}

export async function cancelAdditionalBranch(
  retainedBranchIds: string[],
): Promise<{
  status: string
  effectiveAt: string
}> {
  const response = await api.post<
    DataResponse<{
      status: string
      effective_at: string
    }>
  >('/api/branches/extra/cancel', {
    retained_branch_ids: retainedBranchIds,
  })

  return {
    status: response.data.data.status,
    effectiveAt: response.data.data.effective_at,
  }
}

export async function purchaseAdditionalBranch(
  operationId: string = crypto.randomUUID(),
): Promise<{
  status: string
  invoiceUrl: string | null
}> {
  const response = await api.post<
    DataResponse<{
      status: string
      invoice_url: string | null
    }>
  >(
    '/api/branches/extra/purchase',
    {},
    {
      headers: {
        'Idempotency-Key': operationId,
      },
    },
  )

  return {
    status: response.data.data.status,
    invoiceUrl: response.data.data.invoice_url,
  }
}

export async function createBranch(
  payload: BranchPayload,
): Promise<Branch> {
  return (
    await api.post<DataResponse<Branch>>(
      '/api/branches',
      payload,
    )
  ).data.data
}

export async function updateBranch(
  branchId: string,
  payload: BranchPayload,
): Promise<Branch> {
  return (
    await api.patch<DataResponse<Branch>>(
      `/api/branches/${encodeURIComponent(branchId)}`,
      payload,
    )
  ).data.data
}

export async function enableBranchForPlan(
  branchId: string,
): Promise<Branch> {
  return (
    await api.post<DataResponse<Branch>>(
      `/api/branches/${encodeURIComponent(branchId)}/enable-for-plan`,
    )
  ).data.data
}

export async function getBranchFiscalSettings(
  branchId: string,
): Promise<BranchFiscalSettings> {
  return (
    await api.get<DataResponse<BranchFiscalSettings>>(
      `/api/settings/branches/${encodeURIComponent(branchId)}/fiscal`,
    )
  ).data.data
}

export async function updateBranchFiscalSettings(
  branchId: string,
  payload: BranchFiscalUpdate,
): Promise<BranchFiscalSettings> {
  return (
    await api.put<DataResponse<BranchFiscalSettings>>(
      `/api/settings/branches/${encodeURIComponent(branchId)}/fiscal`,
      payload,
    )
  ).data.data
}

export function branchApiErrors(
  error: unknown,
): Record<string, string> {
  if (!axios.isAxiosError<ApiErrorResponse>(error)) {
    return {
      form: 'No pudimos completar la operación.',
    }
  }

  const validation = error.response?.data?.errors

  if (validation) {
    return Object.fromEntries(
      Object.entries(validation).map(
        ([field, messages]) => [
          field,
          messages[0] ?? 'Revisa este campo.',
        ],
      ),
    )
  }

  return {
    form:
      error.response?.data?.message ??
      'No pudimos completar la operación.',
  }
}
