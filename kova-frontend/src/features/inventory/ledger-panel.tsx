import { useRef, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { isAxiosError } from 'axios'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Table, TableHeader, TableHead, TableBody, TableRow, TableCell } from '@/components/ui/table'
import { Control, Choice } from './inventory-ui'
import { errorMessage, quantity } from './utils'
import { headers, mutation, type Catalog, type Movement, type Balance, type PageData } from './workspace-api'
import { isWholeUnit } from './types'

const labels: Record<string, string> = { INITIAL: 'Inventario inicial', IN: 'Entrada / ajuste positivo', OUT: 'Ajuste negativo', WASTE: 'Merma', PRODUCTION: 'Producción', SALE: 'Venta', COUNT: 'Conteo físico' }
type Attempt = { key: string; payload: Record<string, unknown>; result?: Movement }
export function LedgerPanel({ tenant, branch, membership, data, view }: { tenant: string; branch: string; membership: string; data: Catalog; view: 'balances' | 'movements' | 'production' }) {
  const client = useQueryClient()
  const storageKey = 'kova:inventory:operation:v1:' + tenant + ':' + branch + ':' + membership
  const [initial] = useState(() => { try { const raw = sessionStorage.getItem(storageKey); const saved = raw ? JSON.parse(raw) as Attempt : null; if (saved && (!saved.key || !saved.payload)) throw Error(); return { saved, error: '' } } catch { return { saved: null, error: 'No se pudo leer el intento de inventario guardado. Revisa el almacenamiento antes de operar.' } } })
  const [attempt, setAttempt] = useState<Attempt | null>(initial.saved)
  const [error, setError] = useState(initial.error)
  const [busy, setBusy] = useState(false)
  const lock = useRef(false)
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState(view === 'production')
  const [type, setType] = useState(view === 'production' ? 'PRODUCTION' : 'IN')
  const [supply, setSupply] = useState('')
  const [presentation, setPresentation] = useState('base')
  const [recipe, setRecipe] = useState('')
  const [amount, setAmount] = useState('')
  const [reason, setReason] = useState('')
  const [filter, setFilter] = useState('all')
  const balances = useQuery({ queryKey: ['inventory', tenant, branch, 'balances', page], enabled: view === 'balances' && !!branch, queryFn: async () => (await api.get<PageData<Balance>>('/api/inventory/balances', { headers: headers(tenant, branch), params: { page } })).data })
  const movements = useQuery({ queryKey: ['inventory', tenant, branch, 'movements', page, filter], enabled: view === 'movements' && !!branch, queryFn: async () => (await api.get<PageData<Movement>>('/api/inventory/movements', { headers: headers(tenant, branch), params: { page, ...(filter !== 'all' ? { type: filter } : {}) } })).data })
  function store(a: Attempt) { const raw = JSON.stringify(a); sessionStorage.setItem(storageKey, raw); if (sessionStorage.getItem(storageKey) !== raw) throw Error('storage'); setAttempt(a) }
  async function send(a: Attempt) {
    if (lock.current) return; lock.current = true; setBusy(true); setError('')
    try {
      store(a)
      const response = await mutation(tenant, 'operations', a.payload, 'POST', branch, a.key)
      store({ ...a, result: response.data }); setOpen(false)
      await client.invalidateQueries({ queryKey: ['inventory'] })
    } catch (e) {
      if (isAxiosError(e) && ([403, 404, 422].includes(e.response?.status ?? 0) || ['INVENTORY_INITIAL_EXISTS', 'INVENTORY_RECIPE_CHANGED'].includes(e.response?.data?.code))) {
        sessionStorage.removeItem(storageKey); setAttempt(null)
      }
      setError(errorMessage(e))
    } finally { lock.current = false; setBusy(false) }
  }
  const unresolved = !!attempt && !attempt.result
  const selectedSupply = data.supplies.find(s => s.id === supply)
  const selectedPresentation = selectedSupply?.presentations.find(item => item.id === presentation)
  const movementUnit = presentation === 'base' ? selectedSupply?.unit : selectedPresentation?.unit
  const query = view === 'balances' ? balances : movements
  if (!branch) return <p className="text-sm text-muted-foreground">Selecciona una sucursal para consultar su inventario.</p>
  return <div className="space-y-6">
    <header className="flex flex-wrap items-start justify-between gap-3"><h1 className="text-xl font-semibold">{view === 'production' ? 'Registrar producción' : view === 'balances' ? 'Existencias' : 'Movimientos'}</h1>{view !== 'production' && data.can_manage && <Button disabled={busy || unresolved || !!initial.error} onClick={() => setOpen(!open)}>{open ? 'Ocultar formulario' : 'Registrar movimiento'}</Button>}</header>
    {unresolved && <div className="space-y-3 rounded-xl border p-5"><p className="text-sm">Hay una operación pendiente de verificar. Reintenta la misma solicitud antes de registrar otra.</p><Button disabled={busy} onClick={() => void send(attempt)}>{busy ? 'Verificando…' : 'Verificar operación'}</Button></div>}
    {attempt?.result && <div role="status" className="space-y-2 rounded-xl border p-5"><h2 className="font-semibold">Operación registrada</h2>{attempt.result.lines.map(l => <p key={l.supply_id} className={l.negative ? 'text-sm text-destructive' : 'text-sm'}>{l.name}: {quantity(l.quantity)} {l.unit} · Saldo {quantity(l.balance_after)} {l.unit}{l.negative ? ' · Existencia negativa' : ''}</p>)}<Button variant="outline" onClick={() => { sessionStorage.removeItem(storageKey); setAttempt(null); setAmount(''); setReason('') }}>Listo</Button></div>}
    {open && data.can_manage && !attempt?.result && <form className="space-y-5 rounded-xl border bg-card p-5" onSubmit={e => {
      e.preventDefault(); if (unresolved || busy || initial.error) return
      const r = data.recipes.find(r => r.id === recipe)
      const payload = type === 'PRODUCTION' ? { type, reason, recipe_id: recipe, version: r?.version, batches: amount } : { type, reason, supply_id: supply, quantity: amount, ...(presentation !== 'base' ? { presentation_id: presentation } : {}) }
      const a = { key: crypto.randomUUID(), payload }
      try { store(a); void send(a) } catch { setError('No se puede guardar el intento en esta pestaña. No se envió la operación.') }
    }}><fieldset disabled={busy || unresolved || !!initial.error} className="grid min-w-0 gap-5 sm:grid-cols-2">
      {view !== 'production' && <Control id="movement-type" label="Movimiento"><Choice id="movement-type" value={type} options={Object.entries(labels).filter(([id]) => !['SALE','PRODUCTION','COUNT'].includes(id)).map(([id, name]) => ({ id, name }))} onChange={setType} /></Control>}
      {type === 'PRODUCTION' ? <Control id="production-recipe" label="Receta de producción"><Choice id="production-recipe" value={recipe} options={data.recipes.filter(r => r.type === 'production')} onChange={setRecipe} /></Control> : <><Control id="movement-supply" label="Insumo"><Choice id="movement-supply" value={supply} options={data.supplies} onChange={id => { setSupply(id); setPresentation('base') }} /></Control><Control id="movement-presentation" label="Capturar en"><Choice id="movement-presentation" value={presentation} options={[{ id: 'base', name: 'Unidad base' + (selectedSupply ? ' (' + selectedSupply.unit + ')' : '') }, ...(selectedSupply?.presentations ?? [])]} onChange={setPresentation} /></Control></>}
      <Control id="movement-quantity" label={type === 'PRODUCTION' ? 'Número de lotes' : 'Cantidad'}><Input id="movement-quantity" required type="number" min="0.000001" step={isWholeUnit(movementUnit) ? '1' : 'any'} inputMode={isWholeUnit(movementUnit) ? 'numeric' : 'decimal'} value={amount} onChange={e => setAmount(e.target.value)} placeholder={type === 'PRODUCTION' ? 'Ej. 1' : 'Ej. 24'} /></Control>
      <Control id="movement-reason" label="Motivo"><Input id="movement-reason" required maxLength={500} value={reason} onChange={e => setReason(e.target.value)} placeholder={type === 'PRODUCTION' ? 'Ej. Preparación del día' : 'Ej. Recepción de proveedor'} /></Control>
      <div className="sm:col-span-2"><p className="mb-3 text-xs text-muted-foreground">Se permite saldo negativo y se mostrará una advertencia. Los movimientos registrados no se editan.</p><Button disabled={type === 'PRODUCTION' ? !recipe : !supply} type="submit">{busy ? 'Registrando…' : type === 'PRODUCTION' ? 'Registrar producción' : 'Registrar movimiento'}</Button></div>
    </fieldset></form>}
    {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
    {view === 'movements' && <div className="max-w-xs"><Control id="movement-filter" label="Tipo de movimiento"><Choice id="movement-filter" value={filter} options={[{ id: 'all', name: 'Todos' }, ...Object.entries(labels).map(([id, name]) => ({ id, name }))]} onChange={v => { setFilter(v); setPage(1) }} /></Control></div>}
    {view !== 'production' && (query.isPending ? <p role="status">Cargando…</p> : query.isError ? <p role="alert">{errorMessage(query.error)}</p> : view === 'balances' ? <div className="overflow-hidden rounded-xl border bg-card"><Table><TableHeader><TableRow className="bg-muted/40 hover:bg-muted/40"><TableHead className="pl-5">Insumo</TableHead><TableHead className="text-right">Existencia</TableHead><TableHead>Estado</TableHead><TableHead className="pr-5 text-right">Mínimo / ideal</TableHead></TableRow></TableHeader><TableBody>{balances.data?.data.map(b => <TableRow key={b.id}><TableCell className="py-4 pl-5">{b.name}</TableCell><TableCell className="text-right tabular-nums">{quantity(b.quantity)} {b.unit}</TableCell><TableCell className={b.negative ? 'text-destructive' : 'text-muted-foreground'}>{b.negative ? 'Existencia negativa' : b.below_minimum ? 'Por debajo del mínimo' : 'Disponible'}</TableCell><TableCell className="pr-5 text-right">{quantity(b.minimum)} / {quantity(b.ideal)} {b.unit}</TableCell></TableRow>)}</TableBody></Table>{!balances.data?.data.length && <p className="p-6 text-sm text-muted-foreground">Agrega insumos para consultar existencias.</p>}</div> : <div className="divide-y rounded-xl border">{movements.data?.data.map(m => <details key={m.id} className="p-4"><summary className="cursor-pointer text-sm"><span className="font-medium">{labels[m.type]}</span> · {m.reason} · {new Date(m.created_at).toLocaleString('es-MX')}</summary><p className="mt-3 text-xs text-muted-foreground">Registró: {m.author}</p>{m.lines.map(l => <p key={l.supply_id} className="mt-2 text-sm">{l.name}: {quantity(l.quantity)} {l.unit} · Saldo {quantity(l.balance_after)} {l.unit}</p>)}</details>)}{!movements.data?.data.length && <p className="p-6 text-sm text-muted-foreground">Aún no hay movimientos.</p>}</div>)}
    {view !== 'production' && query.data && query.data.meta.last_page > 1 && <div className="flex justify-end gap-3"><Button variant="outline" disabled={page === 1} onClick={() => setPage(p => p - 1)}>Anterior</Button><span className="self-center text-sm">Página {page}</span><Button variant="outline" disabled={page >= query.data.meta.last_page} onClick={() => setPage(p => p + 1)}>Siguiente</Button></div>}
  </div>
}
