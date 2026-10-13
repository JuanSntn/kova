import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog'
import { Table, TableHeader, TableHead, TableBody, TableCell, TableRow } from '@/components/ui/table'
import { Control } from './inventory-ui'
import { errorMessage } from './utils'
import { mutation, type Catalog } from './workspace-api'

export function CategoriesPanel({ tenant, data }: { tenant: string; data: Catalog }) {
  const client = useQueryClient()
  const [draft, setDraft] = useState<{ id?: string; name: string } | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  return <div className="space-y-6">
    <header className="flex flex-wrap items-start justify-between gap-4"><h1 className="text-xl font-semibold tracking-tight">Categorías de insumos</h1>{data.can_manage && <Button onClick={() => { setError(''); setDraft({ name: '' }) }}>Agregar categoría</Button>}</header>
    {data.categories.length ? <div className="overflow-hidden rounded-xl border bg-card"><Table><TableHeader><TableRow className="bg-muted/40 hover:bg-muted/40"><TableHead className="pl-5">Nombre</TableHead><TableHead><span className="sr-only">Acciones</span></TableHead></TableRow></TableHeader><TableBody>{data.categories.map(c => <TableRow key={c.id}><TableCell className="py-4 pl-5"><p className="font-medium">{c.name}</p></TableCell><TableCell className="pr-4 text-right">{data.can_manage && <Button variant="ghost" size="sm" aria-label={'Editar ' + c.name} onClick={() => { setError(''); setDraft(c) }}>Editar</Button>}</TableCell></TableRow>)}</TableBody></Table></div> : <p className="py-12 text-center text-sm text-muted-foreground">Crea categorías para organizar tus insumos.</p>}
    <Dialog open={!!draft} onOpenChange={open => { if (!open && !busy) setDraft(null) }}><DialogContent><DialogHeader><DialogTitle>{draft?.id ? 'Editar categoría' : 'Nueva categoría'}</DialogTitle><DialogDescription>Estas categorías organizan el inventario, no el menú de venta.</DialogDescription></DialogHeader>{draft && <form className="space-y-4" onSubmit={async e => {
      e.preventDefault(); if (busy) return; setBusy(true); setError('')
      try { await mutation(tenant, 'categories' + (draft.id ? '/' + draft.id : ''), { name: draft.name }, draft.id ? 'PUT' : 'POST'); await client.invalidateQueries({ queryKey: ['inventory'] }); setDraft(null) } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) }
    }}><Control id="category-name" label="Nombre"><Input id="category-name" required maxLength={100} placeholder="Ej. Bebidas" value={draft.name} onChange={e => setDraft({ ...draft, name: e.target.value })} /></Control>{error && <p role="alert" className="text-sm text-destructive">{error}</p>}<Button disabled={busy} type="submit">{busy ? 'Guardando…' : 'Guardar categoría'}</Button></form>}</DialogContent></Dialog>
  </div>
}
