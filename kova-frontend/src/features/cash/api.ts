import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { CashContext, CashContextKey } from './types'
export function cashHeaders(context: CashContextKey) { return { 'X-Tenant-ID': context.tenant, 'X-Branch-ID': context.branch } }
export function useCashContext(context: CashContextKey) {
  return useQuery({ queryKey: ['cash', context.tenant, context.branch, context.membership, 'context'],
    queryFn: async () => (await api.get<{ data: CashContext }>('/api/cash/context', { headers: cashHeaders(context) })).data.data,
    enabled: Boolean(context.tenant && context.branch), staleTime: 0 })
}
