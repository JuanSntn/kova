import { Navigate } from '@tanstack/react-router'

import { useSession } from '@/features/auth/session-context'

export function HomeRedirect() {
  const { session, status } = useSession()

  if (status === 'loading') {
    return null
  }

  const firstTenant = session?.tenants[0]

  if (firstTenant) {
    return (
      <Navigate
        to="/$tenantSlug/dashboard"
        params={{ tenantSlug: firstTenant.slug }}
        replace
      />
    )
  }

  return <Navigate to="/login" replace />
}
