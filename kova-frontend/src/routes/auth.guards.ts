import { redirect } from '@tanstack/react-router'

import type { SessionPayload, TenantSummary } from '@/features/auth/session-types'
import type { AppRouterContext } from '@/routes/route-context'

type GuardContext = AppRouterContext & {
  abilities?: string[]
  activeTenant?: TenantSummary
  session?: SessionPayload | null
}

type GuardArgs = {
  context: GuardContext
  location: { href: string }
  params: { tenantSlug?: string }
}

export async function requireAuth({ context, location }: GuardArgs) {
  const session = await context.ensureSession()

  if (!session) {
    throw redirect({
      to: '/login',
      search: {
        redirect: location.href,
      },
      replace: true,
    })
  }

  return {
    session,
  }
}

export function requireTenantMembership({ context, params }: GuardArgs) {
  const activeTenant = context.session?.tenants.find(
    (tenant) => tenant.slug === params.tenantSlug,
  )

  if (!activeTenant) {
    throw redirect({
      to: '/tenant-unavailable',
      replace: true,
    })
  }

  return {
    activeTenant,
    abilities: activeTenant.abilities,
  }
}

export function requireAbility(requiredAbility: string) {
  return ({ context, params }: GuardArgs) => {
    if (!context.abilities?.includes(requiredAbility)) {
      throw redirect({
        to: '/$tenantSlug/forbidden',
        params: {
          tenantSlug: params.tenantSlug ?? '',
        },
        replace: true,
      })
    }
  }
}

const navigationAbilityMap: Record<string, string | null> = {
  billing: 'billing.view',
  categories: 'categories.view',
  products: 'products.view',
  taxes: 'taxes.view',
}

export function requireNavigationAccess(moduleKey: string) {
  const requiredAbility = navigationAbilityMap[moduleKey] ?? null

  return ({ context, params }: GuardArgs) => {
    if (requiredAbility && !context.abilities?.includes(requiredAbility)) {
      throw redirect({
        to: '/$tenantSlug/forbidden',
        params: {
          tenantSlug: params.tenantSlug ?? '',
        },
        replace: true,
      })
    }
  }
}
