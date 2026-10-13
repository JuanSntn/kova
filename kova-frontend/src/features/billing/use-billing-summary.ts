import { useCallback, useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { queryKeys } from '@/lib/query-keys'
import { getBillingSummary, type BillingSummary } from './api'

export function hasPendingBilling(summary?: BillingSummary) {
  return Boolean(summary && (
    summary.pending_contracts.some(
      contract =>
        contract.state === 'awaiting_payment'
        || contract.state === 'provisioning',
    ) ||
    summary.pending_addon_operations.length ||
    summary.addons.some(a => a.status === 'pending') ||
    summary.pending_changes.some(c => c.status === 'processing' || !c.confirmed)
  ))
}

export function useBillingSummary(tenantId: string) {
  const [deadline, setDeadline] = useState(() => Date.now() + 120_000)
  const [expired, setExpired] = useState(false)
  const [failures, setFailures] = useState(0)
  const paused = expired || failures >= 3

  useEffect(() => {
    const timer = window.setTimeout(() => setExpired(true), Math.max(0, deadline - Date.now()))
    return () => window.clearTimeout(timer)
  }, [deadline])

  const restart = useCallback(() => {
    setFailures(0)
    setExpired(false)
    setDeadline(Date.now() + 120_000)
  }, [])

  const query = useQuery({
    queryKey: queryKeys.billing(tenantId),
    queryFn: async () => {
      try {
        const summary = await getBillingSummary()
        setFailures(0)
        return summary
      } catch (error) {
        setFailures(count => count + 1)
        throw error
      }
    },
    enabled: Boolean(tenantId),
    retry: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    refetchIntervalInBackground: false,
    refetchInterval: q => !paused && Date.now() < deadline && hasPendingBilling(q.state.data) ? 5000 : false,
  })

  return { query, restart, paused, hasPending: hasPendingBilling(query.data) }
}
