import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AlertCircleIcon, CircleCheckIcon, StampIcon } from 'lucide-react'

import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Card } from '@/components/ui/card'
import { Switch } from '@/components/ui/switch'
import { useSession } from '@/features/auth/session-context'
import { type StampUsage, updateStampOverage } from '@/features/billing/api'
import { queryKeys } from '@/lib/query-keys'

export function StampUsageCard({ usage, canManage }: { usage: StampUsage; canManage: boolean }) {
  const { activeTenant } = useSession()
  const queryClient = useQueryClient()
  const mutation = useMutation({
    mutationFn: updateStampOverage,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.billing(activeTenant?.id ?? '') })
    },
  })
  const included = usage.included
  const progress = included === null ? 0 : included === 0 ? 100 : Math.min(100, (usage.used / included) * 100)
  const price = money(usage.estimated_unit_total, usage.currency)

  return (
    <Card className="gap-0 overflow-hidden py-0 shadow-none">
      <header className="flex items-start justify-between gap-4 border-b px-5 py-4">
        <div>
          <div className="flex items-center gap-2">
            <StampIcon className="size-4 text-muted-foreground" aria-hidden />
            <h2 className="text-sm font-semibold">Timbres del periodo</h2>
          </div>
          <p className="mt-1 text-xs text-muted-foreground">Consumo mensual y excedentes reportados a Stripe.</p>
        </div>
        <Badge variant={usage.overages > 0 ? 'outline' : 'secondary'}>
          {usage.overages} excedentes
        </Badge>
      </header>

      <div className="space-y-5 p-5">
        <div>
          <div className="flex items-end justify-between gap-4">
            <div>
              <p className="text-2xl font-semibold tracking-tight">{usage.used}</p>
              <p className="mt-0.5 text-xs text-muted-foreground">
                de {included === null ? 'timbres ilimitados' : `${included} incluidos`}
              </p>
            </div>
            <div className="text-right">
              <p className="text-sm font-medium">{usage.available === null ? 'Sin límite' : usage.available}</p>
              <p className="mt-0.5 text-xs text-muted-foreground">disponibles</p>
            </div>
          </div>
          {included !== null && (
            <div className="mt-3 h-1.5 overflow-hidden rounded-full bg-muted" role="progressbar" aria-valuemin={0} aria-valuemax={included} aria-valuenow={Math.min(usage.used, included)}>
              <div className="h-full rounded-full bg-primary transition-[width]" style={{ width: `${progress}%` }} />
            </div>
          )}
        </div>

        <div className="grid gap-3 border-y py-4 sm:grid-cols-3">
          <Metric label="Precio por excedente" value={price ? `${price} IVA incluido` : 'No disponible'} />
          <Metric label="Estimado del periodo" value={money(usage.estimated_total, usage.currency) ?? '$0.00'} />
          <Metric label="Reporte a Stripe" value={usage.metering.failed > 0 ? 'Requiere atención' : usage.metering.pending > 0 ? `${usage.metering.pending} pendientes` : `${usage.metering.reported} reportados`} />
        </div>

        <div className="flex items-start justify-between gap-4">
          <div className="min-w-0">
            <p className="text-sm font-medium">Permitir timbres excedentes</p>
            <p className="mt-1 max-w-2xl text-xs leading-5 text-muted-foreground">
              Al terminar tus timbres incluidos, Kova podrá emitir nuevos CFDI y cobrarlos en el próximo corte.
            </p>
          </div>
          <Switch
            checked={usage.overage_enabled}
            disabled={!canManage || mutation.isPending || (!usage.overage_enabled && !usage.can_enable_overage)}
            aria-label="Permitir timbres excedentes"
            onCheckedChange={(enabled) => mutation.mutate(enabled)}
          />
        </div>

        {!usage.overage_enabled && (
          <Alert>
            <AlertCircleIcon aria-hidden />
            <AlertDescription>
              No se emitirán nuevos CFDI cuando termines los timbres incluidos. Los excedentes ya utilizados se cobrarán en tu próximo corte.
            </AlertDescription>
          </Alert>
        )}
        {usage.overage_enabled && (
          <div className="flex items-center gap-2 text-xs text-muted-foreground">
            <CircleCheckIcon className="size-4 text-emerald-600" aria-hidden />
            Consentimiento activo para este contrato y precio.
          </div>
        )}
        {mutation.isError && (
          <Alert variant="destructive"><AlertCircleIcon aria-hidden /><AlertDescription>{readError(mutation.error)}</AlertDescription></Alert>
        )}
      </div>
    </Card>
  )
}

function Metric({ label, value }: { label: string; value: string }) {
  return <div><p className="text-xs text-muted-foreground">{label}</p><p className="mt-1 text-sm font-medium">{value}</p></div>
}

function money(value: number | null, currency: string) {
  if (value === null) return null
  return new Intl.NumberFormat('es-MX', { style: 'currency', currency: currency.toUpperCase(), minimumFractionDigits: 2 }).format(value / 100)
}

function readError(error: unknown) {
  if (typeof error === 'object' && error && 'response' in error) {
    const response = (error as { response?: { data?: { message?: string } } }).response
    if (response?.data?.message) return response.data.message
  }
  return 'No pudimos actualizar la preferencia de excedentes.'
}
