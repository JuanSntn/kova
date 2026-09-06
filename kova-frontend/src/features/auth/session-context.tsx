import {
  createContext,
  type ReactNode,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react'

import { getSession } from '@/features/auth/session-api'
import type {
  SessionPayload,
  SessionStatus,
  TenantSummary,
} from '@/features/auth/session-types'
import { setApiTenantId } from '@/lib/api'

type SessionContextValue = {
  activeTenant: TenantSummary | null
  activeTenantId: string | null
  clearSession: () => void
  ensureSession: () => Promise<SessionPayload | null>
  establishSession: (session: SessionPayload) => void
  refreshSession: () => Promise<SessionPayload | null>
  selectTenant: (tenantId: string | null) => void
  session: SessionPayload | null
  status: SessionStatus
}

const SessionContext = createContext<SessionContextValue | null>(null)

export function SessionProvider({ children }: { children: ReactNode }) {
  const [session, setSession] = useState<SessionPayload | null>(null)
  const [status, setStatus] = useState<SessionStatus>('loading')
  const [activeTenantId, setActiveTenantIdState] = useState<string | null>(null)

  const sessionRef = useRef<SessionPayload | null>(null)
  const sessionLoadedRef = useRef(false)
  const pendingSessionRef = useRef<Promise<SessionPayload | null> | null>(null)
  const activeTenantIdRef = useRef<string | null>(null)

  const selectTenant = useCallback((tenantId: string | null) => {
    activeTenantIdRef.current = tenantId
    setApiTenantId(tenantId)
    setActiveTenantIdState(tenantId)
  }, [])

  const clearSession = useCallback(() => {
    sessionRef.current = null
    sessionLoadedRef.current = true
    pendingSessionRef.current = null
    activeTenantIdRef.current = null
    setApiTenantId(null)
    setSession(null)
    setActiveTenantIdState(null)
    setStatus('anonymous')
  }, [])

  const establishSession = useCallback(
    (payload: SessionPayload) => {
      sessionRef.current = payload
      sessionLoadedRef.current = true
      const firstTenantId = payload.tenants[0]?.id ?? null
      activeTenantIdRef.current = firstTenantId
      setApiTenantId(firstTenantId)
      setSession(payload)
      setActiveTenantIdState(firstTenantId)
      setStatus('authenticated')
    },
    [],
  )

  const ensureSession = useCallback(async (): Promise<SessionPayload | null> => {
    if (sessionLoadedRef.current) {
      return sessionRef.current
    }

    if (pendingSessionRef.current) {
      return pendingSessionRef.current
    }

    const pending = getSession().then(
      (payload) => {
        sessionRef.current = payload
        sessionLoadedRef.current = true
        pendingSessionRef.current = null
        const firstTenantId = payload.tenants[0]?.id ?? null
        activeTenantIdRef.current = firstTenantId
        setApiTenantId(firstTenantId)
        setSession(payload)
        setActiveTenantIdState(firstTenantId)
        setStatus('authenticated')
        return payload
      },
      (error: unknown) => {
        const statusCode = (error as { response?: { status?: number } })
          ?.response?.status

        if (statusCode === 401 || statusCode === 419) {
          sessionRef.current = null
          sessionLoadedRef.current = true
          pendingSessionRef.current = null
          activeTenantIdRef.current = null
          setApiTenantId(null)
          setSession(null)
          setActiveTenantIdState(null)
          setStatus('anonymous')
          return null
        }

        pendingSessionRef.current = null
        setStatus('error')
        throw error
      },
    )

    pendingSessionRef.current = pending
    return pending
  }, [])

  const refreshSession = useCallback(async (): Promise<SessionPayload | null> => {
    sessionRef.current = null
    sessionLoadedRef.current = false
    pendingSessionRef.current = null
    setStatus('loading')
    return ensureSession()
  }, [ensureSession])

  useEffect(() => {
    void ensureSession()
  }, [ensureSession])

  useEffect(() => {
    window.addEventListener('kova:session-expired', clearSession)
    return () => window.removeEventListener('kova:session-expired', clearSession)
  }, [clearSession])

  const activeTenant =
    session?.tenants.find((tenant) => tenant.id === activeTenantId) ?? null

  const value = useMemo(
    () => ({
      activeTenant,
      activeTenantId,
      clearSession,
      ensureSession,
      establishSession,
      refreshSession,
      selectTenant,
      session,
      status,
    }),
    [
      activeTenant,
      activeTenantId,
      clearSession,
      ensureSession,
      establishSession,
      refreshSession,
      selectTenant,
      session,
      status,
    ],
  )

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>
}

export function useSession() {
  const context = useContext(SessionContext)

  if (!context) {
    throw new Error('useSession debe usarse dentro de SessionProvider.')
  }

  return context
}