import { useLayoutEffect } from 'react'
import { useQueryClient } from '@tanstack/react-query'

import { useSession } from '@/features/auth/session-context'
import { useParams } from '@tanstack/react-router'
import { TenantLoadingScreen } from '@/components/feedback/tenant-loading-screen'
import { AppLayout } from '@/components/layout/app-layout'
import { checkoutContextLocked, pendingCheckoutContext } from '@/features/pos/checkout-storage'

export function TenantAppLayout() {
  const { tenantSlug } = useParams({ strict: false })
  const { session, activeTenant, selectTenant } = useSession()
  const queryClient = useQueryClient()

  const routeTenant = session?.tenants.find(
    (tenant) => tenant.slug === tenantSlug,
  )

  const isTenantReady = activeTenant?.id === routeTenant?.id

  useLayoutEffect(() => {
    if (isTenantReady || !routeTenant) {
      return
    }

    if (activeTenant?.id) {
      void queryClient.cancelQueries({
        queryKey: ['tenants', activeTenant.id],
      })
    }

    selectTenant(routeTenant.id)
  }, [
    activeTenant?.id,
    isTenantReady,
    queryClient,
    routeTenant?.id,
    selectTenant,
  ])

  if (!routeTenant) {
    return <TenantLoadingScreen />
  }

  if (!isTenantReady) {
    const pending = pendingCheckoutContext()
    const original = session?.tenants.find(tenant => tenant.id === pending?.tenant)
    if (checkoutContextLocked() && original) {
      return <div className="p-6" role="alert">
        <p>Verifica la venta pendiente antes de cambiar de negocio.</p>
        <a className="underline" href={`/${original.slug}/pos`}>Volver al punto de venta</a>
      </div>
    }
    return <TenantLoadingScreen />
  }

  return (
    <AppLayout
      session={session!}
      activeTenant={routeTenant}
    />
  )
}
