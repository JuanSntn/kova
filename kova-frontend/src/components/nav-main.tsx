import { Link, useLocation } from '@tanstack/react-router'
import {
  BookOpenIcon,
  Building2Icon,
  ChartNoAxesCombinedIcon,
  CreditCardIcon,
  LayoutDashboardIcon,
  PackageIcon,
  PercentIcon,
  ReceiptTextIcon,
  ShoppingCartIcon,
  TagsIcon,
} from 'lucide-react'

import {
  SidebarGroup,
  SidebarGroupLabel,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
} from '@/components/ui/sidebar'
import type { NavigationSection } from '@/features/navigation/types'

const iconMap = {
  'book-open': BookOpenIcon,
  'building-2': Building2Icon,
  'chart-no-axes-combined': ChartNoAxesCombinedIcon,
  'credit-card': CreditCardIcon,
  'layout-dashboard': LayoutDashboardIcon,
  package: PackageIcon,
  percent: PercentIcon,
  'receipt-text': ReceiptTextIcon,
  'shopping-cart': ShoppingCartIcon,
  tags: TagsIcon,
} as const

export function NavMain({
  sections,
  tenantSlug,
}: {
  sections: NavigationSection[]
  tenantSlug: string
}) {
  const { pathname } = useLocation()

  if (sections.length === 0) {
    return null
  }

  return (
    <>
      {sections.map((section) => (
        <SidebarGroup key={section.key}>
          {section.label ? <SidebarGroupLabel>{section.label}</SidebarGroupLabel> : null}
          <SidebarMenu>
            {section.items.map((item) => {
              const Icon = iconMap[item.icon as keyof typeof iconMap] ?? null
              const to = `/${tenantSlug}${item.path}`
              const isActive = pathname === to

              return (
                <SidebarMenuItem key={item.key}>
                  <SidebarMenuButton asChild tooltip={item.label} isActive={isActive}>
                    <Link to={to as never} activeOptions={{ exact: true }}>
                      {Icon ? <Icon /> : null}
                      <span>{item.label}</span>
                    </Link>
                  </SidebarMenuButton>
                </SidebarMenuItem>
              )
            })}
          </SidebarMenu>
        </SidebarGroup>
      ))}
    </>
  )
}
