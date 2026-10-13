import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckIcon, ChefHatIcon, Clock3Icon, PlayIcon, WineIcon } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { getPreparationOrders, preparationError, updatePreparationItem, type PreparationItem } from './api'

const queryKey = ['preparation-orders']

export function PreparationPage() {
  const client = useQueryClient()
  const [station, setStation] = useState<'kitchen' | 'bar' | undefined>()
  const orders = useQuery({
    queryKey: [...queryKey, station],
    queryFn: () => getPreparationOrders(station),
    refetchInterval: 5_000,
  })
  const update = useMutation({
    mutationFn: ({ item, status }: { item: PreparationItem; status: 'preparing' | 'ready' }) => updatePreparationItem(item.id, status),
    onSuccess: async () => client.invalidateQueries({ queryKey }),
    onError: error => toast.error(preparationError(error)),
  })
  const independent = orders.data?.meta.mode === 'independent'
  const stationLocked = orders.data?.meta.station_locked === true

  return <div className="space-y-6">
    <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
      <div>
        <h1 className="text-xl font-semibold tracking-tight">Comandas</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Prepara los productos cobrados en esta sucursal y marca cada partida cuando esté lista.
        </p>
      </div>
      <Badge variant="outline" className="w-fit gap-1.5">
        <span className="size-2 rounded-full bg-emerald-500" /> Actualización automática
      </Badge>
    </header>

    {independent && !stationLocked ? <div className="flex gap-2 border-b">
      <StationButton active={!station} onClick={() => setStation(undefined)}>Todas</StationButton>
      <StationButton active={station === 'kitchen'} onClick={() => setStation('kitchen')}>Cocina</StationButton>
      <StationButton active={station === 'bar'} onClick={() => setStation('bar')}>Barra</StationButton>
    </div> : independent ? <div className="rounded-lg border bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
      Estación asignada: {orders.data?.meta.station === 'bar' ? 'Barra' : 'Cocina'}.
    </div> : <div className="rounded-lg border bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
      Cola compartida: Cocina y Barra ven las mismas comandas.
    </div>}

    {orders.isPending ? <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{[1, 2, 3].map(item => <Skeleton key={item} className="h-72 rounded-xl" />)}</div> : null}
    {orders.isError ? <Card className="p-6 text-sm text-destructive">{preparationError(orders.error)}</Card> : null}
    {orders.data && orders.data.data.length === 0 ? <Card className="grid min-h-64 place-items-center p-8 text-center shadow-none">
      <div><CheckIcon className="mx-auto size-9 text-emerald-600" /><h2 className="mt-3 font-semibold">Todo está preparado</h2><p className="mt-1 text-sm text-muted-foreground">Las nuevas ventas aparecerán aquí automáticamente.</p></div>
    </Card> : null}

    <section className="grid items-start gap-4 md:grid-cols-2 xl:grid-cols-3" aria-label="Comandas pendientes">
      {orders.data?.data.map(order => <Card key={order.id} className="gap-0 overflow-hidden py-0 shadow-none">
        <div className="flex items-start justify-between border-b bg-muted/30 p-4">
          <div><p className="font-semibold">{order.folio}</p><p className="mt-1 text-xs text-muted-foreground">Registró: {order.created_by ?? 'Equipo'}</p></div>
          <ElapsedTime value={order.created_at} now={orders.dataUpdatedAt} />
        </div>
        <div className="divide-y">
          {order.items.map(item => <div className="space-y-3 p-4" key={item.id}>
            <div className="flex items-start gap-3">
              <span className="font-semibold tabular-nums">{item.quantity}×</span>
              <div className="min-w-0 flex-1"><p className="font-medium">{item.name}</p><ModifierNames item={item} /></div>
              <StationBadge station={item.station} />
            </div>
            <Button
              className="w-full"
              variant={item.status === 'preparing' ? 'default' : 'outline'}
              disabled={update.isPending}
              onClick={() => update.mutate({ item, status: item.status === 'pending' ? 'preparing' : 'ready' })}
            >
              {item.status === 'pending' ? <><PlayIcon className="size-4" />Comenzar</> : <><CheckIcon className="size-4" />Marcar lista</>}
            </Button>
          </div>)}
        </div>
      </Card>)}
    </section>
  </div>
}

function StationButton({ active, children, onClick }: { active: boolean; children: ReactNode; onClick: () => void }) {
  return <button className={`border-b-2 px-1 pb-3 text-sm font-medium ${active ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground'}`} onClick={onClick}>{children}</button>
}

function StationBadge({ station }: { station: 'kitchen' | 'bar' }) {
  return <Badge variant="secondary" className="gap-1">{station === 'kitchen' ? <ChefHatIcon className="size-3" /> : <WineIcon className="size-3" />}{station === 'kitchen' ? 'Cocina' : 'Barra'}</Badge>
}

function ModifierNames({ item }: { item: PreparationItem }) {
  const names = item.modifiers.map(modifier => modifier.option ?? modifier.name).filter(Boolean)
  return names.length ? <p className="mt-1 text-xs leading-5 text-muted-foreground">{names.join(' · ')}</p> : null
}

function ElapsedTime({ value, now }: { value: string; now: number }) {
  const minutes = Math.max(0, Math.floor((now - new Date(value).getTime()) / 60_000))
  return <span className="flex items-center gap-1 text-xs tabular-nums text-muted-foreground"><Clock3Icon className="size-3.5" />{minutes} min</span>
}
