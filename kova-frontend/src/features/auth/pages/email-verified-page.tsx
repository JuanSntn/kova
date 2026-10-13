import { useEmailVerification } from '@/features/auth/use-email-verification'
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
  const { status, error } = useEmailVerification()
  const verified = status?.status === 'verified'
  return (
    <AuthLayout>
      <Card>
        <CardHeader className="items-center text-center">
          {verified && <span className="mb-2 flex size-11 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-700 dark:text-emerald-300">
            <BadgeCheck className="size-5" aria-hidden />
          </span>}
          <CardTitle className="text-xl">{verified ? 'Correo verificado' : 'Verificación de correo'}</CardTitle>
          <CardDescription>
            {error || (verified
              ? 'Tu correo está confirmado. Ya puedes iniciar sesión.'
              : status?.status === 'pending'
                ? 'Tu correo sigue pendiente. Abre el enlace que recibiste para verificarlo.'
                : status?.status === 'unavailable'
                  ? 'Inicia sesión para comprobar tu cuenta. Si falta verificar el correo, te ayudaremos a reenviar el enlace.'
                  : 'Comprobando el estado de tu correo…')}
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
