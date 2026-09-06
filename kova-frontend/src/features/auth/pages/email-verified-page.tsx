import { Link } from '@tanstack/react-router'
import { BadgeCheck } from 'lucide-react'

import { Button } from '@/components/ui/button'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { AuthLayout } from '@/features/auth/components/auth-layout'

export function EmailVerifiedPage() {
  return (
    <AuthLayout>
      <Card>
        <CardHeader className="items-center text-center">
          <span className="mb-2 flex size-11 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-700 dark:text-emerald-300">
            <BadgeCheck className="size-5" aria-hidden />
          </span>
          <CardTitle className="text-xl">Correo verificado</CardTitle>
          <CardDescription>
            Tu cuenta ya está activa. Ahora puedes entrar y comenzar a configurar Kova.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Button asChild className="w-full">
            <Link to="/login">Iniciar sesión</Link>
          </Button>
        </CardContent>
      </Card>
    </AuthLayout>
  )
}
