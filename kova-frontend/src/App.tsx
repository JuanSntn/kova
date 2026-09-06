import { useEffect } from 'react'
import { QueryClientProvider, useQueryClient } from '@tanstack/react-query'

import { SessionProvider, useSession } from '@/features/auth/session-context'
import { RouterProvider } from '@tanstack/react-router'
import { router } from '@/routes/router'
import { queryClient } from '@/lib/query-client'

function AppRouter() {
  const { ensureSession } = useSession()

  return (
    <RouterProvider
      router={router}
      context={{
        ensureSession,
      }}
    />
  )
}

function SessionExpiryBridge() {
  const { clearSession } = useSession()
  const queryClient = useQueryClient()

  useEffect(() => {
    async function handleExpired() {
      queryClient.clear()
      clearSession()
      await router.invalidate()
      router.navigate({ to: '/login', replace: true })
    }

    window.addEventListener('kova:session-expired', handleExpired)
    return () => window.removeEventListener('kova:session-expired', handleExpired)
  }, [clearSession, queryClient])

  return null
}

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <SessionProvider>
        <AppRouter />
        <SessionExpiryBridge />
      </SessionProvider>
    </QueryClientProvider>
  )
}