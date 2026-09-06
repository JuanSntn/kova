export type TenantSummary = {
  id: string
  name: string
  slug: string
  abilities: string[]
}

export type SessionPayload = {
  user: {
    name: string
    email: string
    email_verified_at: string
  }
  tenants: TenantSummary[]
}

export type SessionStatus =
  | 'loading'
  | 'authenticated'
  | 'anonymous'
  | 'error'