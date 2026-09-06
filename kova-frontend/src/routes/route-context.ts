import type { SessionPayload } from '@/features/auth/session-types'

export type AppRouterContext = {
  ensureSession: () => Promise<SessionPayload | null>
}