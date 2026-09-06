import axios from 'axios'

import { api, getCsrfToken } from '@/lib/api'
import type { SessionPayload } from '@/features/auth/session-types'

export async function getSession() {
  const { data } = await api.get<SessionPayload>('/api/auth/me')
  return data
}

export async function login(email: string, password: string, remember: boolean) {
  await getCsrfToken()
  const { data } = await api.post<SessionPayload>('/api/auth/login', {
    email,
    password,
    remember,
  })

  return data
}

export async function logoutRequest() {
  await getCsrfToken()
  await api.post('/api/auth/logout')
}

export async function register(payload: {
  name: string
  business_name: string
  email: string
  password: string
  password_confirmation: string
}) {
  await getCsrfToken()
  return api.post('/api/auth/register', payload)
}

export async function resendVerification(email: string) {
  await getCsrfToken()
  const { data } = await api.post<{ message: string }>(
    '/api/auth/email/verification-notification',
    { email },
  )

  return data
}

export function getApiError(error: unknown): {
  message: string
  code?: string
  email?: string
  errors?: Record<string, string[]>
} {
  if (axios.isAxiosError<{
    message: string
    code?: string
    email?: string
    errors?: Record<string, string[]>
  }>(error) && error.response?.data) {
    return error.response.data
  }

  return {
    message: 'No pudimos conectar con el servidor. Intenta de nuevo.',
  }
}