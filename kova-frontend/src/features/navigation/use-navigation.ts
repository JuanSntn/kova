import { useQuery } from '@tanstack/react-query'

import { getNavigation } from '@/features/navigation/api'

export function useNavigation(tenantId: string | null) {
  return useQuery({
    queryKey: ['navigation', tenantId],
    queryFn: getNavigation,
    enabled: Boolean(tenantId),
  })
}