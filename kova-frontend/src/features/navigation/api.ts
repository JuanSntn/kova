import { api } from '@/lib/api'

import type { NavigationResponse } from '@/features/navigation/types'

export async function getNavigation() {
  const { data } = await api.get<NavigationResponse>('/api/navigation')
  return data
}