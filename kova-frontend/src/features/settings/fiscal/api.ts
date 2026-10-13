import { api } from '@/lib/api'

export type FiscalProfile = {
  id: string
  rfc: string | null
  legal_name: string | null
  tax_regime_code: string | null
  postal_code: string | null
  fiscal_email: string | null
  default_series: string
  default_product_service_code: string | null
  default_unit_code: string | null
  default_unit_name: string | null
  default_tax_object_code: string | null
  status: 'incomplete' | 'pending_validation' | 'ready' | 'blocked'
  configuration_valid: boolean
  validation_issues: Array<{
    scope: string
    resource_id?: string
    field: string | null
    message: string
  }>
  catalog_version: string | null
  next_folio: number
  tax_regime: { code: string; description: string } | null
  default_product_service: { code: string; description: string } | null
  default_unit: { code: string; description: string } | null
  default_tax_object: { code: string; description: string } | null
  certificate: {
    id: string
    serial_number: string
    valid_from: string
    valid_until: string
    status: string
  } | null
  provider: {
    name: string
    environment: string
    status: string
    last_synced_at: string | null
    error_code: string | null
  } | null
}

export type FiscalProfileInput = {
  rfc: string
  legal_name: string
  tax_regime_code: string
  postal_code: string
  fiscal_email: string
  default_product_service_code: string
  default_unit_code: string
  default_tax_object_code: string
}

export type FiscalSeriesItem = {
  series: string
  next_folio: number
  next_number: string
  is_default: boolean
  is_active: boolean
}

export type FiscalSeriesConfiguration = {
  default_series: string
  series: FiscalSeriesItem[]
}

export async function getFiscalProfile() {
  return (
    await api.get<{ data: FiscalProfile }>(
      '/api/settings/fiscal/profile',
    )
  ).data.data
}

export async function updateFiscalProfile(
  data: FiscalProfileInput,
) {
  return (
    await api.put<{ data: FiscalProfile }>(
      '/api/settings/fiscal/profile',
      data,
    )
  ).data.data
}

export async function uploadCsd(data: FormData) {
  return (
    await api.post<{ data: FiscalProfile }>(
      '/api/settings/fiscal/certificates',
      data,
    )
  ).data.data
}

export async function syncFiscalProvider() {
  return (
    await api.post<{ data: FiscalProfile }>(
      '/api/settings/fiscal/provider/sync',
    )
  ).data.data
}

export async function getFiscalSeries() {
  return (
    await api.get<{ data: FiscalSeriesConfiguration }>(
      '/api/settings/fiscal/series',
    )
  ).data.data
}

export async function createFiscalSeries(series: string) {
  return (
    await api.post<{ data: FiscalSeriesConfiguration }>(
      '/api/settings/fiscal/series',
      { series },
    )
  ).data.data
}

export async function setDefaultFiscalSeries(series: string) {
  return (
    await api.put<{ data: FiscalSeriesConfiguration }>(
      '/api/settings/fiscal/series/default',
      { series },
    )
  ).data.data
}
