import { NavMain } from '@/components/nav-main'
import { NavUser } from '@/components/nav-user'
import { TeamSwitcher } from '@/components/team-switcher'
import { SidebarGroup, SidebarMenu, SidebarMenuSkeleton } from '@/components/ui/sidebar'
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarHeader,
  SidebarRail,
} from '@/components/ui/sidebar'
import type { TenantSummary } from '@/features/auth/session-types'
import type { NavigationSection } from '@/features/navigation/types'

type AppSidebarProps = React.ComponentProps<typeof Sidebar> & {
  activeTenantId: string
  isNavigationPending: boolean
  navigationSections: NavigationSection[]
  onLogout: () => void
  tenants: TenantSummary[]
  user: {
    name: string
    email: string | null
  }
}

export function AppSidebar({
  activeTenantId,
  isNavigationPending,
  navigationSections,
  onLogout,
  tenants,
  user,
  ...props
}: AppSidebarProps) {
  const activeTenant =
    tenants.find((tenant) => tenant.id === activeTenantId) ?? tenants[0]

  const billingNavigationItem = navigationSections
    .flatMap((section) => section.items)
    .find((item) => item.key === 'billing')

  const mainNavigationSections = navigationSections
    .map((section) => ({
      ...section,
      items: section.items.filter((item) => item.key !== 'billing'),
    }))
    .filter((section) => section.items.length > 0)

  return (
    <Sidebar collapsible="icon" {...props}>
      <SidebarHeader>
        <TeamSwitcher
          activeTenant={activeTenant}
        />
      </SidebarHeader>
      <SidebarContent>
        {isNavigationPending ? (
          <SidebarGroup>
            <SidebarMenu>
              <SidebarMenuSkeleton showIcon />
              <SidebarMenuSkeleton showIcon />
              <SidebarMenuSkeleton showIcon />
            </SidebarMenu>
          </SidebarGroup>
        ) : (
          <NavMain
            sections={mainNavigationSections}
            tenantSlug={activeTenant?.slug ?? ''}
          />
        )}
      </SidebarContent>
      <SidebarFooter>
        <NavUser
          canAccessBilling={Boolean(billingNavigationItem)}
          onLogout={onLogout}
          tenantSlug={activeTenant?.slug ?? ''}
          user={user}
        />
      </SidebarFooter>
      <SidebarRail />
    </Sidebar>
  )
}
