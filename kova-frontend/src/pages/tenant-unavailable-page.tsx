import { Link } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'

export function TenantUnavailablePage() {
  return (
    <div className="flex min-h-screen items-center justify-center px-4">
      <div className="max-w-md space-y-4 text-center">
        <h1 className="text-2xl font-semibold">Negocio no disponible</h1>
        <p className="text-sm text-muted-foreground">
          No encontramos este negocio o tu cuenta no tiene acceso.
        </p>
        <Button asChild>
          <Link to="/">Ir a mi panel</Link>
        </Button>
      </div>
    </div>
  )
}