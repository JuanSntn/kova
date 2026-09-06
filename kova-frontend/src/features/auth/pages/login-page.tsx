import { LoginForm } from '@/components/login-form'
import { AuthLayout } from '@/features/auth/components/auth-layout'

export function LoginPage() {
  return (
    <AuthLayout>
      <LoginForm />
    </AuthLayout>
  )
}
