import { Link } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'

export function ForbiddenPage() {
  return (
    <div className="flex min-h-screen items-center justify-center px-4">
      <div className="max-w-md space-y-4 text-center">
        <h1 className="text-2xl font-semibold">Acceso denegado</h1>
        <p className="text-sm text-muted-foreground">
          No tienes permiso para ver esta pantalla.
        </p>
        <Button asChild>
          <Link to="/">Volver a mi panel</Link>
        </Button>
      </div>
    </div>
  )
}