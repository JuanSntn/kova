import {
  createRootRouteWithContext,
  createRoute,
  createRouter,
  Outlet,
  redirect,
} from '@tanstack/react-router'

import { EmailVerifiedPage } from '@/features/auth/pages/email-verified-page'
import { LoginPage } from '@/features/auth/pages/login-page'
import { RegisterPage } from '@/features/auth/pages/register-page'
import { VerifyEmailPage } from '@/features/auth/pages/verify-email-page'
import { DashboardPage } from '@/pages/dashboard-page'
import { BillingPage } from '@/pages/billing-page'
import { NotFoundPage } from '@/pages/not-found-page'
import { TenantUnavailablePage } from '@/pages/tenant-unavailable-page'
import { ForbiddenPage } from '@/pages/forbidden-page'
import { RouteLoadingScreen } from '@/components/feedback/route-loading-screen'
import { RouteErrorPage } from '@/components/feedback/route-error-page'
import { TenantAppLayout } from '@/components/layout/tenant-app-layout'
import { requireAuth, requireTenantMembership, requireNavigationAccess } from '@/routes/auth.guards'
import type { AppRouterContext } from '@/routes/route-context'
import { MenuPage } from '@/features/menu/menu-page'
import { PosPage } from '@/features/pos/pos-page'
import { SalesPage } from '@/features/sales/sales-page'
import { TaxesPage } from '@/features/settings/taxes/taxes-page'
import { BusinessSettingsPage } from '@/features/settings/business/business-settings-page'
import { TicketSettingsPage } from '@/features/settings/tickets/ticket-settings-page'

// --- Root ---
const rootRoute = createRootRouteWithContext<AppRouterContext>()({
  component: Outlet,
  notFoundComponent: NotFoundPage,
})


// --- Rutas públicas ---
const loginRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/login',
  component: LoginPage,
})

const registerRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/register',
  component: RegisterPage,
})

const verifyEmailRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/verify-email',
  component: VerifyEmailPage,
})

const emailVerifiedRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/email-verified',
  component: EmailVerifiedPage,
})

// --- Ruta autenticada (pathless, sin componente visual) ---
const authenticatedRoute = createRoute({
  getParentRoute: () => rootRoute,
  id: '_authenticated',
  beforeLoad: requireAuth,
})

// --- Redirección raíz autenticada ---
const authenticatedIndexRoute = createRoute({
  getParentRoute: () => authenticatedRoute,
  path: '/',
  beforeLoad: ({ context }) => {
    const ctx = context as { session?: { tenants: { slug: string }[] } }
    const firstTenant = ctx.session?.tenants[0]

    if (!firstTenant) {
      throw redirect({
        to: '/tenant-unavailable',
        replace: true,
      })
    }

    throw redirect({
      to: '/$tenantSlug/dashboard',
      params: { tenantSlug: firstTenant.slug },
      replace: true,
    })
  },
})

// --- Tenant unavailable (autenticado, sin sidebar) ---
const tenantUnavailableRoute = createRoute({
  getParentRoute: () => authenticatedRoute,
  path: '/tenant-unavailable',
  component: TenantUnavailablePage,
})

// --- Ruta $tenantSlug (pathless, valida membresía) ---
const tenantRoute = createRoute({
  getParentRoute: () => authenticatedRoute,
  path: '$tenantSlug',
  beforeLoad: requireTenantMembership,
})

// --- Layout con sidebar (pathless) ---
const sidebarLayoutRoute = createRoute({
  getParentRoute: () => tenantRoute,
  id: '_sidebarLayout',
  component: TenantAppLayout,
})

// --- Dashboard ---
const dashboardRoute = createRoute({
  getParentRoute: () => sidebarLayoutRoute,
  path: '/dashboard',
  component: DashboardPage,
})



// --- Menu ---
const menuRoute = createRoute({
  getParentRoute: () => sidebarLayoutRoute,
  path: '/menu',
  beforeLoad: requireNavigationAccess('menu'),
  component: MenuPage,
})

// --- POS ---
const posRoute = createRoute({
  getParentRoute: () => sidebarLayoutRoute,
  path: '/pos',
  beforeLoad: requireNavigationAccess('pos'),
  component: PosPage,
})

// --- Sales ---
const salesRoute = createRoute({
  getParentRoute: () => sidebarLayoutRoute,
  path: '/sales',
  beforeLoad: requireNavigationAccess('sales-history'),
  component: SalesPage,
})

// --- Taxes ---
const taxesRoute = createRoute({
  getParentRoute: () => sidebarLayoutRoute,
  path: '/settings/taxes',
  beforeLoad: requireNavigationAccess('taxes'),
  component: TaxesPage,
})

// --- Configuración de negocio ---
const businessSettingsRoute = createRoute({
  getParentRoute: () => sidebarLayoutRoute,
  path: '/settings/business',
  beforeLoad: requireNavigationAccess(
    'business-settings'
  ),
  component: BusinessSettingsPage,
})
// --- Configuración de tickets ---
const ticketSettingsRoute = createRoute({
  getParentRoute: () => sidebarLayoutRoute,
  path: '/settings/ticket',
  beforeLoad: requireNavigationAccess(
    'ticket-settings'
  ),
  component: TicketSettingsPage,
})

// --- Billing ---
const billingRoute = createRoute({
  getParentRoute: () => sidebarLayoutRoute,
  path: '/billing',
  beforeLoad: requireNavigationAccess('billing'),
  validateSearch: (search: Record<string, unknown>) => ({
    checkout:
      search.checkout === 'success' || search.checkout === 'cancelled'
        ? search.checkout
        : undefined,
    view: search.view === 'plans' ? ('plans' as const) : ('details' as const),
  }),
  component: BillingPage,
})

// --- Forbidden ---
const forbiddenRoute = createRoute({
  getParentRoute: () => sidebarLayoutRoute,
  path: '/forbidden',
  component: ForbiddenPage,
})

// --- Árbol de rutas ---
const routeTree = rootRoute.addChildren([
  loginRoute,
  registerRoute,
  verifyEmailRoute,
  emailVerifiedRoute,
  authenticatedRoute.addChildren([
    authenticatedIndexRoute,
    tenantUnavailableRoute,
    tenantRoute.addChildren([
      sidebarLayoutRoute.addChildren([
        dashboardRoute,
        posRoute,
        menuRoute,
        businessSettingsRoute,
        ticketSettingsRoute,
        salesRoute,
        taxesRoute,
        billingRoute,
        forbiddenRoute,
      ]),
    ]),
  ]),
])

// --- Router ---
export const router = createRouter({
  routeTree,
  context: {
    ensureSession: async () => null,
  },
  defaultPreload: 'intent',
  defaultPendingComponent: RouteLoadingScreen,
  defaultPendingMs: 150,
  defaultPendingMinMs: 300,
  defaultErrorComponent: RouteErrorPage,
})

declare module '@tanstack/react-router' {
  interface Register {
    router: typeof router
  }
}

declare module '@tanstack/history' {
  interface HistoryState {
    verificationEmail?: string
    justRegistered?: boolean
  }
}
