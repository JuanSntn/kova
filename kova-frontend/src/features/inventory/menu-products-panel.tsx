import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Table, TableHeader, TableHead, TableBody, TableRow, TableCell } from '@/components/ui/table'
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet'
import { Control, Choice } from './inventory-ui'
import { ProductInventory } from './product-inventory'
import { headers } from './workspace-api'
import { errorMessage, quantity } from './utils'

type MenuProduct = { id: string; name: string; active: boolean; category: string | null; consumption: { type: string; name: string | null; quantity: string; unit: string | null } }
type Result = { data: MenuProduct[]; meta: { page: number; last_page: number; total: number; can_manage: boolean } }
export function MenuProductsPanel({ tenant }: { tenant: string }) {
  const [search, setSearch] = useState('')
  const [filter, setFilter] = useState('all')
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState<MenuProduct | null>(null)
  const [busy, setBusy] = useState(false)
  const query = useQuery({ queryKey: ['inventory', tenant, 'products', search, filter, page], queryFn: async () => (await api.get<Result>('/api/inventory/products', { headers: headers(tenant), params: { search, page, consumption: filter === 'all' ? undefined : filter } })).data })
  return <div className="min-w-0 space-y-6">
    <header><h1 className="text-xl font-semibold">Productos del menú</h1><p className="mt-1 text-sm text-muted-foreground">Elige qué se descuenta del inventario cuando vendes cada producto.</p></header>
    <div className="grid gap-4 sm:grid-cols-2">
      <Control id="inventory-product-search" label="Buscar producto"><Input id="inventory-product-search" className="h-10" placeholder="Ej. Hamburguesa o refresco" value={search} onChange={e => { setSearch(e.target.value); setPage(1) }} /></Control>
      <Control id="inventory-product-filter" label="Consumo de inventario"><Choice id="inventory-product-filter" value={filter} onChange={value => { setFilter(value); setPage(1) }} options={[{ id: 'all', name: 'Todos' }, { id: 'none', name: 'Sin consumo asignado' }, { id: 'recipe', name: 'Con receta' }, { id: 'supply', name: 'Con insumo directo' }]} /></Control>
    </div>
    {query.isPending ? <p role="status">Cargando productos…</p> : query.isError ? <div role="alert"><p>{errorMessage(query.error)}</p><Button variant="outline" onClick={() => void query.refetch()}>Reintentar</Button></div> : <>
      <p className="text-sm text-muted-foreground">{query.data.meta.total} productos · Los nombres, precios y categorías se administran en Menú.</p>
      <div className="overflow-hidden rounded-xl border bg-card"><Table><TableHeader><TableRow className="bg-muted/40 hover:bg-muted/40"><TableHead className="pl-5">Producto</TableHead><TableHead>Categoría</TableHead><TableHead>Consumo al vender</TableHead><TableHead><span className="sr-only">Acciones</span></TableHead></TableRow></TableHeader><TableBody>{query.data.data.map(product => <TableRow key={product.id}>
        <TableCell className="py-4 pl-5"><span className="font-medium">{product.name}</span>{!product.active && <span className="ml-2 text-xs text-muted-foreground">Inactivo</span>}</TableCell>
        <TableCell>{product.category ?? 'Sin categoría'}</TableCell>
        <TableCell>{product.consumption.type === 'none' ? <span className="text-muted-foreground">Sin consumo asignado</span> : <><div>{product.consumption.name}</div><div className="text-xs text-muted-foreground">{product.consumption.type === 'recipe' ? `${quantity(product.consumption.quantity)} receta(s) por unidad vendida` : `${quantity(product.consumption.quantity)} ${product.consumption.unit} por unidad vendida`}</div></>}</TableCell>
        <TableCell className="pr-4 text-right"><Button variant="outline" onClick={() => setSelected(product)}>{query.data.meta.can_manage ? 'Configurar consumo' : 'Consultar'}</Button></TableCell>
      </TableRow>)}</TableBody></Table>{!query.data.data.length && <p className="p-6 text-sm text-muted-foreground">{search || filter !== 'all' ? 'No hay productos para estos filtros.' : 'Primero agrega tus productos desde Menú. Aquí podrás asignarles una receta o un insumo.'}</p>}</div>
      {query.data.meta.last_page > 1 && <div className="flex items-center justify-end gap-3"><Button variant="outline" disabled={page === 1} onClick={() => setPage(p => p - 1)}>Anterior</Button><span className="text-sm">Página {page} de {query.data.meta.last_page}</span><Button variant="outline" disabled={page >= query.data.meta.last_page} onClick={() => setPage(p => p + 1)}>Siguiente</Button></div>}
    </>}
    <Sheet open={!!selected} onOpenChange={open => { if (!open && !busy) setSelected(null) }}><SheetContent className="data-[side=right]:w-full data-[side=right]:sm:max-w-[500px]"><SheetHeader className="pr-12"><SheetTitle>{selected?.name}</SheetTitle><SheetDescription>Define el consumo de una unidad vendida. Aplica a las próximas ventas de este negocio.</SheetDescription></SheetHeader><div className="min-h-0 flex-1 overflow-y-auto">{selected && <ProductInventory productId={selected.id} onBusyChange={setBusy} />}</div></SheetContent></Sheet>
  </div>
}
