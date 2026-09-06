import { Loader2 } from 'lucide-react'

export function RouteLoadingScreen() {
  return (
    <div className="flex min-h-screen items-center justify-center">
      <div className="space-y-3 text-center">
        <Loader2 className="mx-auto size-7 animate-spin text-primary" />
        <p className="text-sm text-muted-foreground">
          Cargando tu sesión...
        </p>
      </div>
    </div>
  )
}