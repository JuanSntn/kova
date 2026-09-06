import { Outlet, useLocation } from '@tanstack/react-router'

import { AppSidebar } from '@/components/app-sidebar'
import { PageContainer } from '@/components/layout/page-container'
import {
  SidebarInset,
  SidebarProvider,
  SidebarTrigger,
} from '@/components/ui/sidebar'
import { Toaster } from 'sonner'
import { Separator } from '@/components/ui/separator'
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbList,
  BreadcrumbPage,
} from '@/components/ui/breadcrumb'
import type { TenantSummary } from '@/features/auth/session-types'
import type { SessionPayload } from '@/features/auth/session-types'
import { useSession } from '@/features/auth/session-context'
import { useNavigate } from '@tanstack/react-router'
import { logoutRequest } from '@/features/auth/session-api'
import { useNavigation } from '@/features/navigation/use-navigation'
import { queryClient } from '@/lib/query-client'
import { router } from '@/routes/router'

type AppLayoutProps = {
  session: SessionPayload
  activeTenant: TenantSummary
}

const fallbackPageLabels: Record<string, string> = {
  '/billing': 'Facturación',
  '/dashboard': 'Dashboard',
  '/forbidden': 'Acceso denegado',
  '/menu': 'Menú',
  '/pos': 'Punto de venta',
  '/settings/taxes': 'Impuestos',
}

function getTenantRelativePath(pathname: string, tenantSlug: string) {
  const tenantPrefix = `/${tenantSlug}`

  if (pathname === tenantPrefix) {
    return '/dashboard'
  }

  if (pathname.startsWith(`${tenantPrefix}/`)) {
    return pathname.slice(tenantPrefix.length)
  }

  return pathname
}

export function AppLayout({
  session,
  activeTenant,
}: AppLayoutProps) {
  const navigate = useNavigate()
  const { pathname } = useLocation()
  const { clearSession } = useSession()
  const { data: navigation, isPending: isNavigationPending } =
    useNavigation(activeTenant.id)
  const navigationSections = navigation?.sections ?? []
  const currentPath = getTenantRelativePath(pathname, activeTenant.slug)
  const currentNavigationItem = navigationSections
    .flatMap((section) => section.items)
    .find((item) => item.path === currentPath)
  const pageLabel =
    currentNavigationItem?.label ?? fallbackPageLabels[currentPath] ?? 'Kova'
  const breadcrumbTitle =
    pageLabel === 'Kova' ? 'Kova' : `Kova - ${pageLabel}`

  async function handleLogout() {
    try {
      await logoutRequest()
    } finally {
      queryClient.clear()
      clearSession()
      await router.invalidate()
      navigate({ to: '/login', replace: true })
    }
  }

  function handleTenantChange(tenant: TenantSummary) {
    navigate({
      to: '/$tenantSlug/dashboard',
      params: { tenantSlug: tenant.slug },
    })
  }

  return (
    <SidebarProvider>
      <AppSidebar
        activeTenantId={activeTenant.id}
        isNavigationPending={isNavigationPending}
        navigationSections={navigationSections}
        onLogout={handleLogout}
        onTenantChange={handleTenantChange}
        tenants={session.tenants}
        user={session.user}
      />
      <SidebarInset>
        <header className="flex h-16 shrink-0 items-center border-b">
          <div className="flex items-center gap-2 px-4">
            <SidebarTrigger className="-ml-1" />
            <Separator orientation="vertical" className="mr-2 h-4" />
            <Breadcrumb>
              <BreadcrumbList>
                <BreadcrumbItem>
                  <BreadcrumbPage>{breadcrumbTitle}</BreadcrumbPage>
                </BreadcrumbItem>
              </BreadcrumbList>
            </Breadcrumb>
          </div>
        </header>
        <PageContainer>
          <Outlet />
        </PageContainer>
        <Toaster
          richColors
          closeButton
          position="top-right"
        />
      </SidebarInset>
    </SidebarProvider>
  )
}
