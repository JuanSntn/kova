import { api } from '@/lib/api'

export type SatEntry = {
  code: string
  description: string
}

export type SatPostalEntry = SatEntry & {
  state: string | null
  municipality: string | null
  locality: string | null
  timezone: string
}

type SatResponse<T> = {
  data: T
  meta: {
    catalog_version: string
  }
}

export async function getTaxRegimes(rfc: string) {
  return (
    await api.get<SatResponse<SatEntry[]>>(
      '/api/sat/catalogs/tax-regimes',
      { params: { rfc } },
    )
  ).data
}

export async function getPostalCode(code: string) {
  return (
    await api.get<SatResponse<SatPostalEntry>>(
      `/api/sat/catalogs/postal-codes/${encodeURIComponent(code)}`,
    )
  ).data
}

export async function searchProductServices(q: string) {
  return (
    await api.get<SatResponse<SatEntry[]>>(
      '/api/sat/catalogs/product-services',
      { params: { q, limit: 20 } },
    )
  ).data.data
}

export async function searchUnits(q: string) {
  return (
    await api.get<SatResponse<SatEntry[]>>(
      '/api/sat/catalogs/units',
      { params: { q, limit: 20 } },
    )
  ).data.data
}

export async function getTaxObjects() {
  return (
    await api.get<SatResponse<SatEntry[]>>(
      '/api/sat/catalogs/tax-objects',
    )
  ).data
}

export async function getCfdiUses(
  rfc: string,
  taxRegimeCode: string,
) {
  return (
    await api.get<SatResponse<SatEntry[]>>(
      '/api/sat/catalogs/cfdi-uses',
      {
        params: {
          rfc,
          tax_regime_code: taxRegimeCode,
        },
      },
    )
  ).data
}

export async function getPublicCatalogs(
  token: string,
  rfc: string,
  taxRegimeCode?: string,
) {
  return (
    await api.get<
      SatResponse<{
        tax_regimes: SatEntry[]
        cfdi_uses: SatEntry[]
      }>
    >(
      `/api/public/factura/${encodeURIComponent(token)}/catalogs`,
      {
        params: {
          rfc,
          tax_regime_code: taxRegimeCode || undefined,
        },
      },
    )
  ).data
}
