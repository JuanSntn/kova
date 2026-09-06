import { Link } from '@tanstack/react-router'
import { AlertTriangleIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'

export function RouteErrorPage() {
  return (
    <div className="flex min-h-screen items-center justify-center px-4">
      <div className="max-w-md space-y-4 text-center">
        <AlertTriangleIcon className="mx-auto size-8 text-destructive" />
        <h1 className="text-2xl font-semibold">Algo salió mal</h1>
        <p className="text-sm text-muted-foreground">
          Ocurrió un error inesperado. Inténtalo nuevamente.
        </p>
        <Button asChild>
          <Link to="/">Volver al inicio</Link>
        </Button>
      </div>
    </div>
  )
}