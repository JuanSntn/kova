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
  onTenantChange: (tenant: TenantSummary) => void
  tenants: TenantSummary[]
  user: {
    name: string
    email: string
  }
}

export function AppSidebar({
  activeTenantId,
  isNavigationPending,
  navigationSections,
  onLogout,
  onTenantChange,
  tenants,
  user,
  ...props
}: AppSidebarProps) {
  const activeTenant =
    tenants.find((tenant) => tenant.id === activeTenantId) ?? tenants[0]

  return (
    <Sidebar collapsible="icon" {...props}>
      <SidebarHeader>
        <TeamSwitcher
          activeTenant={activeTenant}
          onTenantChange={onTenantChange}
          tenants={tenants}
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
            sections={navigationSections}
            tenantSlug={activeTenant?.slug ?? ''}
          />
        )}
      </SidebarContent>
      <SidebarFooter>
        <NavUser onLogout={onLogout} user={user} />
      </SidebarFooter>
      <SidebarRail />
    </Sidebar>
  )
}
