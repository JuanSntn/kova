import { CheckIcon, ChevronsUpDownIcon } from 'lucide-react'

import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  useSidebar,
} from '@/components/ui/sidebar'
import type { TenantSummary } from '@/features/auth/api'

export function TeamSwitcher({
  activeTenant,
  onTenantChange,
  tenants,
}: {
  activeTenant?: TenantSummary
  onTenantChange: (tenant: TenantSummary) => void
  tenants: TenantSummary[]
}) {
  const { isMobile } = useSidebar()

  if (!activeTenant) {
    return null
  }

  return (
    <SidebarMenu>
      <SidebarMenuItem>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <SidebarMenuButton
              size="lg"
              className="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
            >
              <div className="flex aspect-square size-8 items-center justify-center overflow-hidden rounded-lg">
                <img
                  src="/brand/kova-logo.png"
                  alt=""
                  className="size-8 object-contain"
                />
              </div>
              <div className="grid flex-1 text-left text-sm leading-tight">
                <span className="truncate font-medium">{activeTenant.name}</span>
                <span className="truncate text-xs">Espacio de trabajo</span>
              </div>
              <ChevronsUpDownIcon className="ml-auto" />
            </SidebarMenuButton>
          </DropdownMenuTrigger>
          <DropdownMenuContent
            className="min-w-56 rounded-lg"
            align="start"
            side={isMobile ? 'bottom' : 'right'}
            sideOffset={4}
          >
            <DropdownMenuLabel className="text-xs text-muted-foreground">
              Negocios autorizados
            </DropdownMenuLabel>
            {tenants.map((tenant) => (
              <DropdownMenuItem
                key={tenant.id}
                onClick={() => onTenantChange(tenant)}
                className="gap-2 p-2"
              >
                <div className="flex size-6 items-center justify-center overflow-hidden rounded-md border">
                  <img
                    src="/brand/kova-logo.png"
                    alt=""
                    className="size-5 object-contain"
                  />
                </div>
                <span className="min-w-0 flex-1 truncate">{tenant.name}</span>
                {tenant.id === activeTenant.id && <CheckIcon className="size-4" />}
              </DropdownMenuItem>
            ))}
          </DropdownMenuContent>
        </DropdownMenu>
      </SidebarMenuItem>
    </SidebarMenu>
  )
}
