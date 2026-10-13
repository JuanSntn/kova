import axios from 'axios'
import { api, getCsrfToken } from '@/lib/api'

export type TeamMember = {
  id: string
  name: string
  username: string | null
  account_type: 'email' | 'tenant_local'
  role: 'owner' | 'cashier' | 'waiter' | 'kitchen' | 'bar' | null
  status: 'active' | 'suspended' | 'invited'
  last_login_at: string | null
  branches: { id: string; name: string }[]
}

export type UsersResponse = {
  data: TeamMember[]
  meta: {
    users: { current: number; limit: number | null; unlimited: boolean; remaining: number | null; can_create: boolean }
    staff_login: { branches: { id: string; name: string; url: string }[] }
  }
}

export type StaffPayload = {
  name: string
  username: string
  password: string
  password_confirmation: string
  role: 'cashier' | 'waiter' | 'kitchen' | 'bar'
  branch_ids: string[]
}

export async function getUsers() { return (await api.get<UsersResponse>('/api/settings/users')).data }
export async function createUser(payload: StaffPayload) { return (await api.post('/api/settings/users', payload)).data }
export async function updateUser(id: string, payload: Pick<StaffPayload, 'name' | 'username' | 'role'>) { return (await api.patch(`/api/settings/users/${id}`, payload)).data }
export async function deleteUser(id: string) { return (await api.delete(`/api/settings/users/${id}`)).data }
export async function syncUserBranches(id: string, branchIds: string[]) { return (await api.put(`/api/settings/users/${id}/branches`, { branch_ids: branchIds })).data }
export async function suspendUser(id: string) { return (await api.post(`/api/settings/users/${id}/suspend`)).data }
export async function activateUser(id: string) { return (await api.post(`/api/settings/users/${id}/activate`)).data }
export async function resetUserPassword(id: string, password: string) { return (await api.put(`/api/settings/users/${id}/password`, { password, password_confirmation: password })).data }

export function userApiError(error: unknown) {
  if (axios.isAxiosError<{ message?: string; errors?: Record<string, string[]> }>(error)) {
    const validationMessage = Object.values(error.response?.data?.errors ?? {})
      .flat()
      .find(message => typeof message === 'string' && message.length > 0)

    return validationMessage ?? error.response?.data?.message ?? 'No pudimos completar la operación.'
  }
  return 'No pudimos conectar con el servidor.'
}

export async function getStaffLoginContext(code: string) {
  return (await api.get<{ data: { business: string; branch: string } }>(`/api/auth/staff/${encodeURIComponent(code)}`)).data.data
}

export async function staffLogin(code: string, username: string, password: string) {
  await getCsrfToken()
  return (await api.post(`/api/auth/staff/${encodeURIComponent(code)}/login`, { username, password })).data
}
