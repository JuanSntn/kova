import { BadgeCheck, Settings2 } from 'lucide-react'

import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Separator } from '@/components/ui/separator'
import { PageHeader } from '@/components/layout/page-header'
import { useSession } from '@/features/auth/session-context'

export function DashboardPage() {
  const { activeTenant, session } = useSession()

  return (
    <>
      <PageHeader
        title="Dashboard"
        description="Resumen general del negocio."
      />

      <div>
        <p className="text-sm text-muted-foreground">{activeTenant?.name}</p>
        <h1 className="mt-1 text-2xl font-semibold tracking-tight">
          Hola, {session?.user.name}
        </h1>
        <p className="mt-2 text-sm text-muted-foreground">
          Tu espacio de trabajo está listo para continuar con la configuración.
        </p>
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        <Card>
          <CardHeader>
            <div className="mb-2 flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
              <Settings2 className="size-5" aria-hidden />
            </div>
            <CardTitle>Configuración inicial</CardTitle>
            <CardDescription>
              El acceso y el aislamiento de tu negocio ya están activos.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="flex items-start gap-3 rounded-lg border bg-muted/40 p-4">
              <BadgeCheck
                className="mt-0.5 size-5 shrink-0 text-emerald-600"
                aria-hidden
              />
              <div>
                <p className="font-medium">Cuenta verificada</p>
                <p className="mt-1 text-sm leading-6 text-muted-foreground">
                  La sesión y el contexto multi-tenant están listos para los
                  módulos operativos.
                </p>
              </div>
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>Contexto activo</CardTitle>
            <CardDescription>
              Solo se envía el UUID público de este negocio.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div>
              <p className="text-sm text-muted-foreground">Negocio</p>
              <p className="mt-1 font-medium">{activeTenant?.name}</p>
            </div>
            <Separator />
            <div>
              <p className="text-sm text-muted-foreground">Ruta</p>
              <p className="mt-1 font-mono text-xs">{activeTenant?.slug}</p>
            </div>
          </CardContent>
        </Card>
      </div>
    </>
  )
}