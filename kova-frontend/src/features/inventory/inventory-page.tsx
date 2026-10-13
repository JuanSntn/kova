import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { isAxiosError } from 'axios'
import { toast } from 'sonner'
import { useSession } from '@/features/auth/session-context'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Skeleton } from '@/components/ui/skeleton'
import { SupplyForm } from './supply-form'
import { emptySupply, units, type Supply } from './types'
import { catalog } from './workspace-api'
import { fetchSupplies, saveSupply } from './api'

export function InventoryPage() {
  const { activeTenant } = useSession()
  if (!activeTenant) return null
  return <InventoryContent key={activeTenant.id} tenantId={activeTenant.id} />
}
function InventoryContent({ tenantId }: { tenantId: string }) {
  const client = useQueryClient()
  const categoryQuery = useQuery({ queryKey: ['inventory', tenantId, 'catalog'], queryFn: () => catalog(tenantId) })
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [editor, setEditor] = useState<{ supply: Supply; editing: boolean } | null>(null)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  const query = useQuery({ queryKey: ['inventory', tenantId, search, page], queryFn: () => fetchSupplies(tenantId, search, page) })
  function open(supply: Supply, editing: boolean) { setError(''); setFieldErrors({}); setEditor({ supply, editing }) }
  async function save(supply: Supply) {
    if (saving || !editor) return
    setSaving(true); setError(''); setFieldErrors({})
    try {
      await saveSupply(tenantId, supply, editor.editing)
      setEditor(null)
      toast.success('Insumo guardado')
      await client.invalidateQueries({ queryKey: ['inventory', tenantId] })
    } catch (e) {
      setError(isAxiosError(e) ? e.response?.data?.message ?? 'No pudimos confirmar el guardado. Conserva el formulario y revisa la conexión.' : 'No pudimos guardar el insumo.')
      if (isAxiosError(e)) setFieldErrors(e.response?.data?.errors ?? {})
    } finally { setSaving(false) }
  }
  if (editor) return <div className="space-y-6">
    <header><h1 className="text-xl font-semibold tracking-tight">{editor.editing ? 'Editar insumo' : 'Nuevo insumo'}</h1><p className="mt-1 text-sm text-muted-foreground">Define cómo compras y cuentas este insumo.</p></header>
    <SupplyForm key={editor.supply.id} initial={editor.supply} onSave={supply => void save(supply)} onCancel={() => setEditor(null)} categories={categoryQuery.data?.categories ?? []} taxes={query.data?.taxes ?? []} saving={saving} serverError={error} fieldErrors={fieldErrors} />
  </div>
  return <div className="space-y-6">
    <header className="flex flex-wrap items-start justify-between gap-4">
      <div><h1 className="text-xl font-semibold tracking-tight">Insumos</h1><p className="mt-1 text-sm text-muted-foreground">Organiza lo que compras y produces en tu negocio.</p></div>
      {query.data?.meta.can_manage && <Button onClick={() => open(emptySupply(), false)}>Agregar insumo</Button>}
    </header>
    <div className="flex flex-wrap items-center justify-between gap-3">
      <Input className="h-10 w-full sm:max-w-sm" aria-label="Buscar insumo" placeholder="Buscar insumo por nombre…" value={search} onChange={e => { setSearch(e.target.value); setPage(1) }} />
      {query.data && <p className="text-xs text-muted-foreground">{query.data.meta.total} {query.data.meta.total === 1 ? 'insumo' : 'insumos'}</p>}
    </div>
    {query.isError ? <div role="alert" className="space-y-3 rounded-xl border p-6"><p className="text-sm">{isAxiosError(query.error) ? query.error.response?.data?.message ?? 'No pudimos cargar los insumos.' : 'No pudimos cargar los insumos.'}</p><Button variant="outline" onClick={() => void query.refetch()}>Reintentar</Button></div>
      : query.isPending ? <div aria-label="Cargando insumos" className="space-y-3">{[1, 2, 3].map(i => <Skeleton key={i} className="h-14 w-full" />)}</div>
      : query.data.data.length === 0 ? <div className="rounded-xl border border-dashed px-6 py-16 text-center"><h2 className="text-base font-semibold">{search ? 'No encontramos insumos' : 'Agrega tu primer insumo'}</h2><p className="mx-auto mt-2 max-w-md text-sm text-muted-foreground">{search ? 'Prueba con otro nombre.' : 'Empieza con algo que compras: una bebida, harina o aceite. Después agrega sus presentaciones.'}</p>{!search && query.data.meta.can_manage && <Button variant="outline" className="mt-5" onClick={() => open(emptySupply(), false)}>Agregar insumo</Button>}</div>
      : <div className="overflow-hidden rounded-xl border bg-card"><Table><TableHeader><TableRow className="bg-muted/40 hover:bg-muted/40"><TableHead className="pl-5">Insumo</TableHead><TableHead>Unidad base</TableHead><TableHead>Origen</TableHead><TableHead>Presentaciones</TableHead><TableHead className="text-right">Mínimo / ideal</TableHead><TableHead><span className="sr-only">Acciones</span></TableHead></TableRow></TableHeader><TableBody>{query.data.data.map(supply => <TableRow key={supply.id}>
        <TableCell className="py-4 pl-5"><p className="max-w-xs truncate font-medium" title={supply.name}>{supply.name}</p><p className="mt-1 text-xs text-muted-foreground">{supply.category}</p></TableCell>
        <TableCell>{units[supply.unit].label}</TableCell><TableCell><Badge variant="secondary" className="font-normal">{supply.produced ? 'Producido' : 'Comprado'}</Badge></TableCell>
        <TableCell><span className="text-muted-foreground">{supply.presentations.length || 'Sin configurar'}</span></TableCell>
        <TableCell className="text-right tabular-nums">{supply.minimum ? Number(supply.minimum).toLocaleString('es-MX') : '—'} / {supply.ideal ? Number(supply.ideal).toLocaleString('es-MX') : '—'} <span className="text-muted-foreground">{supply.unit}</span></TableCell>
        <TableCell className="pr-4 text-right">{query.data.meta.can_manage && <Button variant="ghost" size="sm" aria-label={'Editar ' + supply.name} onClick={() => open(supply, true)}>Editar</Button>}</TableCell>
      </TableRow>)}</TableBody></Table></div>}
    {query.data && query.data.meta.last_page > 1 && <div className="flex items-center justify-end gap-3"><Button variant="outline" disabled={page === 1} onClick={() => setPage(p => p - 1)}>Anterior</Button><span className="text-xs text-muted-foreground">Página {page} de {query.data.meta.last_page}</span><Button variant="outline" disabled={page >= query.data.meta.last_page} onClick={() => setPage(p => p + 1)}>Siguiente</Button></div>}
  </div>
}
