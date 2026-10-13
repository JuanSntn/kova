import { useRef, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { isAxiosError } from 'axios'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Check, LoaderCircle } from 'lucide-react'
import { Input } from '@/components/ui/input'
import { Table, TableHeader, TableHead, TableBody, TableRow, TableCell } from '@/components/ui/table'
import { Control, Choice } from './inventory-ui'
import { headers, mutation, type Balance, type Catalog, type PageData } from './workspace-api'
import { errorMessage, quantity } from './utils'
import { isWholeUnit } from './types'

type Attempt = { key: string; payload: { type: 'COUNT'; supply_id: string; quantity: string; expected_quantity: string; reason: string } }
export function AdjustmentPanel({ tenant, branch, membership, data }: { tenant: string; branch: string; membership: string; data: Catalog }) {
  const client = useQueryClient()
  const storage = `kova:inventory:count:${tenant}:${branch}:${membership}`
  const [initial] = useState(() => { try { const raw = sessionStorage.getItem(storage); const saved = raw ? JSON.parse(raw) as Attempt : null; if (saved && (!saved.key || !saved.payload)) throw Error(); return { saved, error: '' } } catch { return { saved: null, error: 'No se pudo leer el conteo guardado. Revisa el almacenamiento de esta pestaña.' } } })
  const [attempt, setAttempt] = useState<Attempt | null>(initial.saved)
  const [error, setError] = useState(initial.error)
  const [busy, setBusy] = useState(false)
  const lock = useRef(false)
  const [search, setSearch] = useState('')
  const [category, setCategory] = useState('all')
  const [page, setPage] = useState(1)
  const [counts, setCounts] = useState<Record<string, string>>({})
  const [reason, setReason] = useState('Conteo físico de inventario')
  const [notice, setNotice] = useState('')
  const query = useQuery({ queryKey: ['inventory', tenant, branch, 'adjustments', page, search, category], enabled: !!branch, queryFn: async () => (await api.get<PageData<Balance & { category: string; last_count: string | null }>>('/api/inventory/balances', { headers: headers(tenant, branch), params: { page, search, category: category === 'all' ? undefined : category } })).data })
  async function send(a: Attempt) {
    if (lock.current) return
    lock.current = true; setBusy(true); setError(''); setNotice('')
    try {
      const raw = JSON.stringify(a); sessionStorage.setItem(storage, raw)
      if (sessionStorage.getItem(storage) !== raw) throw Error('No se pudo guardar el intento. No se envió el ajuste.')
      setAttempt(a)
      await mutation(tenant, 'operations', a.payload, 'POST', branch, a.key)
      sessionStorage.removeItem(storage); setAttempt(null)
      setCounts(values => { const next = { ...values }; delete next[a.payload.supply_id]; return next })
      setNotice('Conteo guardado. La diferencia quedó registrada en Movimientos.')
      await client.invalidateQueries({ queryKey: ['inventory'] })
    } catch (e) {
      if (isAxiosError(e) && ([403, 404, 422].includes(e.response?.status ?? 0) || e.response?.data?.code === 'INVENTORY_COUNT_STALE')) {
        sessionStorage.removeItem(storage); setAttempt(null)
        await client.invalidateQueries({ queryKey: ['inventory'] })
      }
      setError(errorMessage(e))
    } finally { lock.current = false; setBusy(false) }
  }
  if (!branch) return <p>Selecciona una sucursal para contar su inventario.</p>
  return <div className="space-y-6">
    <header><h1 className="text-xl font-semibold">Ajuste</h1><p className="mt-1 text-sm text-muted-foreground">Ingresa la cantidad física de cada insumo en su unidad base. Guardaremos la diferencia como un movimiento.</p></header>
    <div className="grid gap-4 sm:grid-cols-2"><Control id="adjust-category" label="Categoría"><Choice id="adjust-category" value={category} onChange={v => { setCategory(v); setPage(1) }} options={[{ id: 'all', name: 'Ver todas' }, ...data.categories]} /></Control><Control id="adjust-search" label="Buscar insumo"><Input id="adjust-search" className="h-10" placeholder="Nombre del insumo…" value={search} onChange={e => { setSearch(e.target.value); setPage(1) }} /></Control></div>
    {data.can_manage && <Control id="adjust-reason" label="Motivo del ajuste"><Input id="adjust-reason" className="h-10" maxLength={500} value={reason} disabled={busy || !!attempt} onChange={e => setReason(e.target.value)} /></Control>}
    {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
    {notice && <p role="status" className="text-sm">{notice}</p>}
    {attempt && <div className="flex flex-wrap items-center gap-3 rounded-lg border p-4"><p className="text-sm">Hay un ajuste pendiente de verificar.</p><Button disabled={busy} onClick={() => void send(attempt)}>{busy ? 'Verificando…' : 'Verificar ajuste'}</Button></div>}
    {query.isPending ? <p>Cargando existencias…</p> : query.isError ? <p role="alert">{errorMessage(query.error)}</p> : <div className="overflow-hidden rounded-xl border bg-card"><Table><TableHeader><TableRow className="bg-muted/40 hover:bg-muted/40"><TableHead className="pl-5">Nombre</TableHead><TableHead className="text-right">Último conteo</TableHead><TableHead className="text-right">Cantidad actual</TableHead><TableHead>Unidad</TableHead><TableHead>Cantidad real</TableHead><TableHead className="pr-5">Categoría</TableHead></TableRow></TableHeader><TableBody>{query.data.data.map(row => <TableRow key={row.id}><TableCell className="py-4 pl-5">{row.name}</TableCell><TableCell className="text-right">{row.last_count === null ? '—' : quantity(row.last_count)}</TableCell><TableCell className="text-right tabular-nums">{quantity(row.quantity)}</TableCell><TableCell>{row.unit}</TableCell><TableCell><form className="flex w-44 overflow-hidden rounded-lg border border-emerald-600/60 focus-within:ring-2 focus-within:ring-emerald-600/25" onSubmit={e => { e.preventDefault(); if (busy || attempt || initial.error || !reason.trim()) return; void send({ key: crypto.randomUUID(), payload: { type: 'COUNT', supply_id: row.id, quantity: counts[row.id], expected_quantity: row.quantity, reason } }) }}><Input aria-label={'Cantidad real de ' + row.name} className="h-10 min-w-0 flex-1 rounded-none border-0 shadow-none focus-visible:ring-0" type="number" min="0" step={isWholeUnit(row.unit) ? '1' : '0.000001'} inputMode={isWholeUnit(row.unit) ? 'numeric' : 'decimal'} required placeholder="Sin contar" value={counts[row.id] ?? ''} disabled={!data.can_manage || busy || !!attempt || !!initial.error} onChange={e => setCounts(v => ({ ...v, [row.id]: e.target.value }))} /><Button type="submit" variant="ghost" size="icon" className="h-10 w-10 shrink-0 rounded-none border-l border-emerald-600/20 bg-emerald-600/10 text-emerald-700 hover:bg-emerald-600/20 hover:text-emerald-800 dark:text-emerald-400" aria-label={'Confirmar conteo de ' + row.name} title="Confirmar conteo" disabled={!data.can_manage || busy || !!attempt || !!initial.error || !reason.trim() || !counts[row.id]}>{busy && attempt?.payload.supply_id === row.id ? <LoaderCircle aria-hidden="true" className="size-4 animate-spin" /> : <Check aria-hidden="true" className="size-4" />}</Button></form></TableCell><TableCell className="pr-5">{row.category}</TableCell></TableRow>)}</TableBody></Table>{!query.data.data.length && <p className="p-6 text-sm text-muted-foreground">No hay insumos para estos filtros.</p>}</div>}
    {query.data && query.data.meta.last_page > 1 && <div className="flex justify-end gap-3"><Button variant="outline" disabled={page === 1} onClick={() => setPage(p => p - 1)}>Anterior</Button><span>Página {page}</span><Button variant="outline" disabled={page >= query.data.meta.last_page} onClick={() => setPage(p => p + 1)}>Siguiente</Button></div>}
  </div>
}
