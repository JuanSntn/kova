import { InventoryWorkspace } from '@/features/inventory/inventory-workspace'
import {
  createRootRouteWithContext,
  createRoute,
  createRouter,
  Outlet,
  redirect,
} from '@tanstack/react-router'

import { PasswordRecoveryPage, ResetPasswordPage } from '@/features/auth/pages/password-recovery-page'
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
import { CashPage } from '@/features/cash/cash-page'
import { PosPage } from '@/features/pos/pos-page'
import { SalesPage } from '@/features/sales/sales-page'
import { TaxesPage } from '@/features/settings/taxes/taxes-page'
import { BusinessSettingsPage } from '@/features/settings/business/business-settings-page'
import { TicketSettingsPage } from '@/features/settings/tickets/ticket-settings-page'
import { FiscalSettingsPage } from '@/features/settings/fiscal/fiscal-settings-page'
import { BranchesSettingsPage } from '@/features/settings/branches/branches-settings-page'
import { InvoiceLandingPage } from '@/features/fiscal/invoice-landing-page'
import { PublicInvoicePage } from '@/features/fiscal/public-invoice-page'
import { StaffLoginPage } from '@/features/auth/pages/staff-login-page'
import { UsersSettingsPage } from '@/features/settings/users/users-settings-page'
import { PreparationPage } from '@/features/preparation/preparation-page'

const rootRoute = createRootRouteWithContext<AppRouterContext>()({
  component: Outlet,
  notFoundComponent: NotFoundPage,
})

const loginRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/login',
  component: LoginPage,
})

const staffLoginRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/login/$branchLoginCode',
  component: StaffLoginPage,
})

const forgotPasswordRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/forgot-password',
  component: PasswordRecoveryPage,
})

const resetPasswordRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/reset-password',
  component: ResetPasswordPage,
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

const invoiceLandingRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/factura',
  component: InvoiceLandingPage,
})

const publicInvoiceRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/factura/$token',
  component: PublicInvoicePage,
})

const authenticatedRoute = createRoute({
  getParentRoute: () => rootRoute,
  id: '_authenticated',
  beforeLoad: requireAuth,
})

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

const tenantUnavailableRoute = createRoute({
  getParentRoute: () => authenticatedRoute,
  path: '/tenant-unavailable',
  component: TenantUnavailablePage,
})

const tenantRoute = createRoute({
  getParentRoute: () => authenticatedRoute,
  path: '$tenantSlug',
  beforeLoad: requireTenantMembership,
})

const sidebarLayoutRoute = createRoute({
  getParentRoute: () => tenantRoute,
  id: '_sidebarLayout',
  component: TenantAppLayout,
})

const dashboardRoute = createRoute({
  getParentRoute: () => sidebarLayoutRoute,
  path: '/dashboard',
  component: DashboardPage,
})

const menuRoute = createRoute({
  getParentRoute: () => sidebarLayoutRoute,
  path: '/menu',
  beforeLoad: requireNavigationAccess('menu'),
  component: MenuPage,
})

const inventoryRoute = createRoute({
  getParentRoute: () => sidebarLayoutRoute,
  path: '/inventory',
  beforeLoad: requireNavigationAccess('inventory'),
  component: InventoryWorkspace,
})

const posRoute = createRoute({
  getParentRoute: () => sidebarLayoutRoute,
  path: '/pos',
  beforeLoad: requireNavigationAccess('pos'),
  component: PosPage,
})

const cashRoute = createRoute({ getParentRoute: () => sidebarLayoutRoute, path: '/cash', beforeLoad: requireNavigationAccess('cash'), component: CashPage })

const preparationRoute = createRoute({ getParentRoute: () => sidebarLayoutRoute, path: '/preparation', beforeLoad: requireNavigationAccess('preparation'), component: PreparationPage })

const salesRoute = createRoute({
  getParentRoute: () => sidebarLayoutRoute,
  path: '/sales',
  beforeLoad: requireNavigationAccess('sales-history'),
  component: SalesPage,
})

const taxesRoute = createRoute({
  getParentRoute: () => sidebarLayoutRoute,
  path: '/settings/taxes',
  beforeLoad: requireNavigationAccess('taxes'),
  component: TaxesPage,
})

const businessSettingsRoute = createRoute({
  getParentRoute: () => sidebarLayoutRoute,
  path: '/settings/business',
  beforeLoad: requireNavigationAccess('business-settings'),
  component: BusinessSettingsPage,
})

const branchesSettingsRoute = createRoute({
  getParentRoute: () => sidebarLayoutRoute,
  path: '/settings/branches',
  beforeLoad: requireNavigationAccess('branches-settings'),
  component: BranchesSettingsPage,
})

const usersSettingsRoute = createRoute({
  getParentRoute: () => sidebarLayoutRoute,
  path: '/settings/users',
  beforeLoad: requireNavigationAccess('users-settings'),
  component: UsersSettingsPage,
})

const ticketSettingsRoute = createRoute({
  getParentRoute: () => sidebarLayoutRoute,
  path: '/settings/ticket',
  beforeLoad: requireNavigationAccess('ticket-settings'),
  component: TicketSettingsPage,
})

const fiscalSettingsRoute = createRoute({
  getParentRoute: () => sidebarLayoutRoute,
  path: '/settings/fiscal',
  beforeLoad: requireNavigationAccess('fiscal-settings'),
  component: FiscalSettingsPage,
})

const billingRoute = createRoute({
  getParentRoute: () => sidebarLayoutRoute,
  path: '/billing',
  beforeLoad: requireNavigationAccess('billing'),
  validateSearch: (search: Record<string, unknown>) => ({
    change: search.change === 'scheduled' ? 'scheduled' : undefined,
    checkout:
      search.checkout === 'success' || search.checkout === 'cancelled'
        ? search.checkout
        : undefined,
    view: search.view === 'plans' ? ('plans' as const) : ('details' as const),
  }),
  component: BillingPage,
})

const forbiddenRoute = createRoute({
  getParentRoute: () => sidebarLayoutRoute,
  path: '/forbidden',
  component: ForbiddenPage,
})

const routeTree = rootRoute.addChildren([
  loginRoute,
  staffLoginRoute,
  forgotPasswordRoute,
  resetPasswordRoute,
  registerRoute,
  verifyEmailRoute,
  emailVerifiedRoute,
  invoiceLandingRoute,
  publicInvoiceRoute,
  authenticatedRoute.addChildren([
    authenticatedIndexRoute,
    tenantUnavailableRoute,
    tenantRoute.addChildren([
      sidebarLayoutRoute.addChildren([
        dashboardRoute,
        posRoute,
        cashRoute,
        preparationRoute,
        menuRoute,
        inventoryRoute,
        businessSettingsRoute,
        branchesSettingsRoute,
        usersSettingsRoute,
        ticketSettingsRoute,
        fiscalSettingsRoute,
        salesRoute,
        taxesRoute,
        billingRoute,
        forbiddenRoute,
      ]),
    ]),
  ]),
])

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
