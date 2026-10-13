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
import { useBranch } from '@/features/branches/branch-context'

export function TeamSwitcher({
  activeTenant,
}: {
  activeTenant?: TenantSummary
}) {
  const { isMobile } = useSidebar()
  const { branches, activeBranch, selectBranch, isLoading } = useBranch()

  if (!activeTenant) {
    return null
  }

  const canSelectBranch = activeTenant.can_select_branch !== false
  const showBranchSelector = canSelectBranch && !isLoading && branches.length > 0
  const contextLabel = isLoading
    ? 'Cargando sucursal…'
    : activeBranch?.name ?? (canSelectBranch && branches.length ? 'Selecciona sucursal' : 'Espacio de trabajo')

  const button = <SidebarMenuButton
    size="lg"
    tooltip={activeTenant.name}
    disabled={!showBranchSelector}
    className={showBranchSelector ? 'data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground' : 'cursor-default opacity-100 disabled:opacity-100 hover:bg-transparent active:bg-transparent'}
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
      <span className="truncate text-xs text-muted-foreground">{contextLabel}</span>
    </div>
    {showBranchSelector && <ChevronsUpDownIcon className="ml-auto" />}
  </SidebarMenuButton>

  return (
    <SidebarMenu>
      <SidebarMenuItem>
        {showBranchSelector ? <DropdownMenu>
          <DropdownMenuTrigger asChild>
            {button}
          </DropdownMenuTrigger>
          <DropdownMenuContent
            className="min-w-64 rounded-lg"
            align="start"
            side={isMobile ? 'bottom' : 'right'}
            sideOffset={4}
          >
            <DropdownMenuLabel className="text-xs text-muted-foreground">
              Cambiar sucursal
            </DropdownMenuLabel>
            {branches.map((branch) => (
              <DropdownMenuItem
                key={branch.id}
                onClick={() => selectBranch(branch.id)}
                className="gap-2 px-2 py-2"
              >
                <span className="min-w-0 flex-1 truncate">{branch.name}</span>
                {branch.id === activeBranch?.id && <CheckIcon className="size-4" />}
              </DropdownMenuItem>
            ))}
          </DropdownMenuContent>
        </DropdownMenu> : button}
      </SidebarMenuItem>
    </SidebarMenu>
  )
}
