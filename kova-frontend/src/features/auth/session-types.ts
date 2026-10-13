export type TenantSummary = {
  id: string
  name: string
  slug: string
  abilities: string[]
  can_select_branch?: boolean
  membership_id?: string
  branches?: { id: string; code: string; name: string; timezone: string | null }[]
  initial_branch_id?: string | null
}

export type SessionPayload = {
  user: {
    name: string
    email: string | null
    email_verified_at: string | null
    account_type: 'email' | 'tenant_local'
  }
  tenants: TenantSummary[]
}

export type SessionStatus =
  | 'loading'
  | 'authenticated'
  | 'anonymous'
  | 'error'
