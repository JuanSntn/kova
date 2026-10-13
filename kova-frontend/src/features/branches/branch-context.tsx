import { createContext, type ReactNode, useCallback, useContext, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api, setApiBranchId } from '@/lib/api'
import { useSession } from '@/features/auth/session-context'
import { checkoutContextLocked, pendingCheckoutContext } from '@/features/pos/checkout-storage'
import { toast } from 'sonner'

export type OperableBranch = { id: string; code: string; name: string; timezone: string | null }
type Value = { branches: OperableBranch[]; activeBranch: OperableBranch | null; activeBranchId: string | null; selectBranch: (id: string) => void; isLoading: boolean }
const Context = createContext<Value | null>(null)
const EMPTY_BRANCHES: OperableBranch[] = []

export function BranchProvider({ children }: { children: ReactNode }) {
  const { activeTenantId } = useSession()
  const [selection, setSelection] = useState<{ tenantId: string | null; branchId: string | null }>({ tenantId: null, branchId: null })
  const [switchingBranch, setSwitchingBranch] = useState<OperableBranch | null>(null)
  const previousTenantId = useRef<string | null>(null)
  const reloadTimer = useRef<number | null>(null)
  const activeBranchId = selection.tenantId === activeTenantId ? selection.branchId : null

  useLayoutEffect(() => {
    if (previousTenantId.current !== null && previousTenantId.current !== activeTenantId) {
      window.dispatchEvent(new CustomEvent('kova:branch-changed'))
    }
    previousTenantId.current = activeTenantId
    setApiBranchId(null)
    setSelection({ tenantId: activeTenantId, branchId: null })
  }, [activeTenantId])

  const query = useQuery({
    queryKey: ['tenants', activeTenantId, 'operable-branches'],
    queryFn: async () => (await api.get<{ data: OperableBranch[] }>('/api/context/branches')).data.data,
    enabled: Boolean(activeTenantId),
  })
  const branches = query.data ?? EMPTY_BRANCHES

  useEffect(() => {
    const stored = activeTenantId ? localStorage.getItem(`kova:branch:${activeTenantId}`) : null
    const pending = pendingCheckoutContext()
    const next = pending?.tenant === activeTenantId ? pending.branch
      : branches.some((branch) => branch.id === stored) ? stored : branches[0]?.id ?? null
    setSelection({ tenantId: activeTenantId, branchId: next }); setApiBranchId(next)
  }, [activeTenantId, branches])

  useEffect(() => () => {
    if (reloadTimer.current !== null) {
      window.clearTimeout(reloadTimer.current)
    }
  }, [])

  const selectBranch = useCallback((id: string) => {
    const branch = branches.find((candidate) => candidate.id === id)
    if (!branch || switchingBranch) return
    if (id === activeBranchId) return
    if (checkoutContextLocked()) { toast.error('Verifica la venta pendiente antes de cambiar de sucursal.'); return }
    if (activeTenantId) localStorage.setItem(`kova:branch:${activeTenantId}`, id)
    setSwitchingBranch(branch)
    setSelection({ tenantId: activeTenantId, branchId: id }); setApiBranchId(id)
    window.dispatchEvent(new CustomEvent('kova:branch-changed'))
    reloadTimer.current = window.setTimeout(() => window.location.reload(), 350)
  }, [activeBranchId, activeTenantId, branches, switchingBranch])

  const value = useMemo(() => ({ branches, activeBranch: branches.find((branch) => branch.id === activeBranchId) ?? null, activeBranchId, selectBranch, isLoading: query.isLoading }), [activeBranchId, branches, query.isLoading, selectBranch])
  return <Context.Provider value={value}>
    {children}
    {switchingBranch ? (
      <div
        className="fixed inset-0 z-[100] flex items-center justify-center bg-background/80 px-6 backdrop-blur-[1px]"
        role="status"
        aria-live="assertive"
      >
        <div className="rounded-xl border bg-card px-5 py-4 text-center shadow-lg">
          <p className="text-sm text-muted-foreground">Cambiando a</p>
          <p className="mt-1 text-base font-semibold">{switchingBranch.name}</p>
        </div>
      </div>
    ) : null}
  </Context.Provider>
}

export function useBranch() { const value = useContext(Context); if (!value) throw new Error('useBranch debe usarse dentro de BranchProvider.'); return value }
