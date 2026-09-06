import type { ReactNode } from 'react'
import { useAbility } from '@/features/permissions/access-context'

export function Can({
  ability,
  children,
  fallback = null,
}: {
  ability: string
  children: ReactNode
  fallback?: ReactNode
}) {
  const hasAbility = useAbility(ability)

  if (!hasAbility) return fallback
  return <>{children}</>
}