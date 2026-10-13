import { useRef, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useSession } from '@/features/auth/session-context'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription, SheetFooter } from '@/components/ui/sheet'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Choice, Control, SearchChoice } from '@/features/inventory/inventory-ui'
import { catalog, headers, type Catalog } from '@/features/inventory/workspace-api'
import { isWholeUnit, units, type Unit } from '@/features/inventory/types'
import { errorMessage } from '@/features/inventory/utils'
import type { ModifierGroup, ModifierOption, ProductModifierGroup } from './types'
import { ChevronDownIcon, PlusIcon } from 'lucide-react'

export function ModifiersPage() {
  const { activeTenant } = useSession()
  return activeTenant ? <Workspace key={activeTenant.id} tenant={activeTenant.id} inventory={activeTenant.abilities.includes('inventory.manage')} /> : null
}
function Workspace({ tenant, inventory }: { tenant: string; inventory: boolean }) {
  const cache = useQueryClient()
  const groups = useQuery({ queryKey: ['modifiers', tenant], queryFn: async () => (await api.get<{ data: ModifierGroup[]; can_manage: boolean }>('/api/pos/modifiers', { headers: headers(tenant) })).data })
  const products = useQuery({ queryKey: ['modifier-products', tenant], queryFn: async () => (await api.get<{ data: { id: string; name: string }[] }>('/api/pos/products', { headers: headers(tenant) })).data.data })
  const [draft, setDraft] = useState<ModifierGroup | null>(null)
  const [product, setProduct] = useState('')
  const [busy, setBusy] = useState(false)
  const sending = useRef(false)
  const [error, setError] = useState('')
  async function save() {
    if (!draft || sending.current) return
    sending.current = true; setBusy(true); setError('')
    try {
      await api.request({ method: draft.id ? 'PUT' : 'POST', url: '/api/pos/modifiers' + (draft.id ? '/' + draft.id : ''), headers: headers(tenant), data: { ...draft, options: draft.options.map(o => ({ ...o, id: o.id || undefined })) } })
      await cache.invalidateQueries({ queryKey: ['modifiers', tenant] }); setDraft(null)
    } catch (e) { setError(errorMessage(e)) } finally { sending.current = false; setBusy(false) }
  }
  return <section className="space-y-6">
    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
      <div>
        <h1 className="text-xl font-semibold tracking-tight">
          Modificadores
        </h1>

        <p className="mt-1 text-sm text-muted-foreground">
          Crea tamaños y extras, y asígnalos a tus productos.
        </p>
      </div>

      {groups.data?.can_manage && (
        <Button
          size="sm"
          onClick={() => {
            setError('');
            setDraft({
              id: '',
              name: '',
              kind: 'extras',
              version: 1,
              is_active: true,
              options: [
                {
                  id: '',
                  name: '',
                },
              ],
            });
          }}
        >
          <PlusIcon className="size-4" />
          Crear grupo
        </Button>
      )}
    </div>
    {groups.isPending && <p role="status">Cargando modificadores…</p>}
    {groups.isError && <p role="alert">No se pudieron cargar los modificadores. <Button variant="outline" onClick={() => void groups.refetch()}>Reintentar</Button></p>}
    {groups.data && <div className="overflow-hidden rounded-xl border bg-card">
      <Table>
        <TableHeader><TableRow className="bg-muted/40 hover:bg-muted/40">
          <TableHead className="pl-5">Grupo</TableHead>
          <TableHead>Opciones</TableHead>
          <TableHead className="w-[140px]">Estado</TableHead>
          <TableHead className="w-[90px] pr-4 text-right"><span className="sr-only">Acciones</span></TableHead>
        </TableRow></TableHeader>
        <TableBody>{groups.data.data.map(g => <TableRow key={g.id} className="group">
          <TableCell className="py-4 pl-5"><p className="font-medium">{g.name}</p><p className="mt-0.5 text-xs text-muted-foreground">{g.kind === 'size' ? 'Tamaño · una elección' : 'Extras'}</p></TableCell>
          <TableCell className="max-w-xs whitespace-normal text-muted-foreground">{g.options.map(o => o.name).join(', ')}</TableCell>
          <TableCell><span className="inline-flex items-center text-xs text-muted-foreground"><span aria-hidden="true" className={'mr-1.5 size-1.5 rounded-full ' + (g.is_active ? 'bg-emerald-500' : 'bg-muted-foreground/50')} />{g.is_active ? 'Activo' : 'Inactivo'}</span></TableCell>
          <TableCell className="pr-4 text-right">{groups.data.can_manage && <Button variant="ghost" onClick={() => { setError(''); setDraft(structuredClone(g)) }}>Editar</Button>}</TableCell>
        </TableRow>)}</TableBody>
      </Table>
      {!groups.data.data.length && <div className="space-y-1 border-t px-5 py-10 text-center"><p className="text-sm font-medium">Aún no tienes grupos</p><p className="text-sm text-muted-foreground">Empieza con tamaños de bebida o extras de hamburguesa.</p></div>}
    </div>}
    {groups.data?.can_manage && <section aria-labelledby="assign-products-title" className="grid gap-5 rounded-xl border bg-card p-5 md:grid-cols-2 md:items-center md:gap-8">
      <div className="space-y-1"><h2 id="assign-products-title" className="text-base font-semibold">Asignar a productos</h2><p className="max-w-md text-sm text-muted-foreground">Elige un producto para configurar sus grupos, precios y consumo.</p></div>
      <div className="min-w-0">{products.isError ? <Button variant="outline" onClick={() => void products.refetch()}>Reintentar productos</Button> : <Control id="modifier-product" label="Producto del menú"><SearchChoice id="modifier-product" value="" options={products.data ?? []} onChange={setProduct} /></Control>}</div>
    </section>}
    <Sheet open={!!draft} onOpenChange={open => { if (!open && !busy) setDraft(null) }}><SheetContent className="data-[side=right]:w-full data-[side=right]:sm:max-w-[500px]" onInteractOutside={e => { if (busy) e.preventDefault() }} onEscapeKeyDown={e => { if (busy) e.preventDefault() }}>
      <SheetHeader><SheetTitle>{draft?.id ? 'Editar grupo' : 'Nuevo grupo'}</SheetTitle><SheetDescription>Comparte estas opciones entre varios productos.</SheetDescription></SheetHeader>
      {draft && <form className="flex min-h-0 flex-1 flex-col" onSubmit={e => { e.preventDefault(); void save() }}><fieldset disabled={busy} className="min-h-0 flex-1 space-y-5 overflow-y-auto px-4 py-4">
        <Control id="modifier-name" label="Nombre"><Input id="modifier-name" name="name" className="h-10" required maxLength={100} placeholder="Ej. Tamaño de bebida" value={draft.name} onChange={e => setDraft({ ...draft, name: e.target.value })} /></Control>
        <Control id="modifier-kind" label="Tipo"><Choice id="modifier-kind" value={draft.kind} options={[{ id: 'size', name: 'Tamaño · una elección obligatoria' }, { id: 'extras', name: 'Extras y preferencias' }]} onChange={kind => { if (!draft.id) setDraft({ ...draft, kind: kind as ModifierGroup['kind'] }) }} /></Control>
        <Control id="modifier-active" label="Estado"><Choice id="modifier-active" value={draft.is_active ? 'yes' : 'no'} options={[{ id: 'yes', name: 'Activo' }, { id: 'no', name: 'Inactivo' }]} onChange={v => setDraft({ ...draft, is_active: v === 'yes' })} /></Control>
        <div className="space-y-3"><h2 className="text-base font-semibold">Opciones</h2>{draft.options.map((o, i) => <div key={i} className="flex items-center gap-2"><Input name={'option-' + i} aria-label={'Opción ' + (i + 1)} className="h-10" required maxLength={100} placeholder="Ej. Grande" value={o.name} onChange={e => setDraft({ ...draft, options: draft.options.map((v, n) => n === i ? { ...v, name: e.target.value } : v) })} /><Button type="button" variant="ghost" disabled={draft.options.length === 1} onClick={() => setDraft({ ...draft, options: draft.options.filter((_, n) => n !== i) })}>Quitar</Button></div>)}<Button type="button" variant="outline" disabled={draft.options.length >= 30} onClick={() => setDraft({ ...draft, options: [...draft.options, { id: '', name: '' }] })}>Agregar opción</Button></div>
        {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
      </fieldset><SheetFooter><Button type="submit" disabled={busy}>{busy ? 'Guardando…' : 'Guardar grupo'}</Button></SheetFooter></form>}
    </SheetContent></Sheet>
    {product && <Assignment key={product} tenant={tenant} product={product} name={products.data?.find(p => p.id === product)?.name ?? ''} groups={groups.data?.data ?? []} inventory={inventory} close={() => setProduct('')} />}
  </section>
}

function Assignment({ tenant, product, name, groups, inventory, close }: { tenant: string; product: string; name: string; groups: ModifierGroup[]; inventory: boolean; close: () => void }) {
  const [busy, setBusy] = useState(false)
  const query = useQuery({ queryKey: ['product-modifiers', tenant, product], queryFn: async () => (await api.get<{ data: ProductModifierGroup[] }>('/api/pos/products/' + product + '/modifiers', { headers: headers(tenant) })).data.data })
  return <Sheet open onOpenChange={open => { if (!open && !busy) close() }}><SheetContent className="gap-0 data-[side=right]:w-full data-[side=right]:sm:max-w-[520px]" onInteractOutside={e => { if (busy) e.preventDefault() }} onEscapeKeyDown={e => { if (busy) e.preventDefault() }}><SheetHeader className="border-b px-5 py-4 pr-12"><SheetTitle className="text-lg font-semibold">{name}</SheetTitle><SheetDescription className="mt-1">Configura las opciones disponibles al vender este producto.</SheetDescription></SheetHeader>{query.data ? <AssignmentForm tenant={tenant} product={product} groups={groups} initial={query.data} inventory={inventory} close={close} onBusy={setBusy} /> : <div className="p-5" role="status">{query.isError ? <><p>No se pudo cargar la configuración.</p><Button variant="outline" onClick={() => void query.refetch()}>Reintentar</Button></> : 'Cargando…'}</div>}</SheetContent></Sheet>
}
function AssignmentForm({ tenant, product, groups, initial, inventory, close, onBusy }: { tenant: string; product: string; groups: ModifierGroup[]; initial: ProductModifierGroup[]; inventory: boolean; close: () => void; onBusy: (busy: boolean) => void }) {
  const [draft, setDraft] = useState(initial)
  const [selectedGroupId, setSelectedGroupId] = useState(initial[initial.length - 1]?.id ?? '')
  const [openOptionId, setOpenOptionId] = useState(initial[0]?.options[0]?.id ?? '')
  const [openRulesId, setOpenRulesId] = useState('')
  const [busy, setBusy] = useState(false)
  const busyRef = useRef(false)
  const [error, setError] = useState('')
  const cache = useQueryClient()
  const stock = useQuery({ queryKey: ['inventory', tenant, 'catalog'], queryFn: () => catalog(tenant), enabled: inventory, retry: false })
  function change(id: string, option: string, patch: Partial<ModifierOption>) { setDraft(current => current.map(g => g.id === id ? { ...g, options: g.options.map(o => o.id === option ? { ...o, ...patch } : o) } : g)) }
  return <form className="flex min-h-0 flex-1 flex-col" onSubmit={async e => {
    e.preventDefault(); if (busyRef.current) return; busyRef.current = true; setBusy(true); onBusy(true); setError('')
    try {
      await api.put('/api/pos/products/' + product + '/modifiers', { groups: draft.map(g => ({ id: g.id, min: g.min, max: g.max, options: g.options.map(({ id, price, effect, target_type, target_id, quantity, source_type, source_id, source_quantity }) => ({
        id,
        price,
        effect,
        target_type: effect === 'none' ? null : target_type,
        target_id: effect === 'none' ? null : target_id,
        quantity: effect === 'none' ? null : quantity,
        source_type: effect === 'swap' ? source_type : null,
        source_id: effect === 'swap' ? source_id : null,
        source_quantity: effect === 'swap' ? source_quantity : null,
      })) })) }, { headers: headers(tenant) })
      await cache.invalidateQueries({ queryKey: ['product-modifiers', tenant, product] }); close()
    } catch (e) { setError(errorMessage(e)) } finally { busyRef.current = false; setBusy(false); onBusy(false) }
  }}><fieldset disabled={busy} className="min-h-0 flex-1 overflow-y-auto">
      <section className="border-b px-5 py-5">
        <Control id="assign-group" label={draft.length ? 'Agregar otro grupo' : 'Agregar grupo'}><Choice id="assign-group" value={selectedGroupId} options={groups.filter(g => g.is_active && (!draft.some(item => item.id === g.id) || g.id === selectedGroupId)).map(g => ({ id: g.id, name: g.name }))} onChange={id => {
          setSelectedGroupId(id)
          const group = groups.find(g => g.id === id)
          if (!group || draft.some(item => item.id === id)) return
          setDraft(current => [...current, { id, name: group.name, kind: group.kind, min: group.kind === 'size' ? 1 : 0, max: group.kind === 'size' ? 1 : group.options.length, options: group.options.map(option => ({ ...option, price: '0.00', effect: 'none', target_type: 'supply', target_id: '', quantity: '1', source_type: 'supply', source_id: '', source_quantity: '1' })) }])
          setOpenOptionId(group.options[0]?.id ?? '')
        }} /></Control>
        <p className="mt-2 text-xs leading-5 text-muted-foreground">Los grupos definen tamaños, extras o sustituciones disponibles al cobrar.</p>
      </section>

      {!draft.length && <p className="px-5 py-8 text-sm text-muted-foreground">Este producto se vende sin modificadores.</p>}

      {draft.map(group => <section key={group.id} className="border-b last:border-b-0">
        <div className="space-y-4 px-5 py-5">
          <div className="flex items-start justify-between gap-4">
            <div className="min-w-0"><h2 className="text-base font-semibold">{group.name}</h2><p className="mt-1 text-xs leading-5 text-muted-foreground">{group.kind === 'size' ? 'Tamaño · conserva o sustituye la receta completa' : 'Extras y sustituciones · ajustan la receta base'}</p></div>
            <Button type="button" size="sm" variant="ghost" onClick={() => { setDraft(current => current.filter(item => item.id !== group.id)); if (selectedGroupId === group.id) setSelectedGroupId(''); if (group.options.some(option => option.id === openOptionId)) setOpenOptionId('') }}>Desvincular</Button>
          </div>

          {group.kind === 'extras' && <div>
            <button type="button" className="flex w-full items-center justify-between gap-3 rounded-md py-1 text-left text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring/50" aria-expanded={openRulesId === group.id} aria-controls={group.id + '-rules'} onClick={() => setOpenRulesId(current => current === group.id ? '' : group.id)}>
              <span><span className="block">Reglas de selección</span><span className="mt-0.5 block text-xs font-normal text-muted-foreground">{selectionSummary(group)}</span></span>
              <ChevronDownIcon className={'size-4 text-muted-foreground transition-transform ' + (openRulesId === group.id ? 'rotate-180' : '')} />
            </button>
            {openRulesId === group.id && <div id={group.id + '-rules'} className="mt-4 grid grid-cols-2 gap-4">
              {(['min', 'max'] as const).map(key => <Control key={key} id={group.id + key} label={key === 'min' ? 'Mínimo' : 'Máximo'}><Input id={group.id + key} name={key} className="h-10" type="number" min={key === 'min' ? 0 : 1} max={group.options.length} required value={group[key]} onChange={event => setDraft(current => current.map(item => item.id === group.id ? { ...item, [key]: Number(event.target.value) } : item))} /></Control>)}
            </div>}
          </div>}
        </div>

        <div className="border-t">
          {group.options.map(option => {
            const open = openOptionId === option.id
            return <div key={option.id} className="border-b last:border-b-0">
              <button type="button" className="flex w-full items-center justify-between gap-4 px-5 py-4 text-left outline-none transition-colors hover:bg-muted/40 focus-visible:bg-muted/40" aria-expanded={open} aria-controls={option.id + '-editor'} onClick={() => setOpenOptionId(current => current === option.id ? '' : option.id)}>
                <span className="min-w-0"><span className="block font-medium">{option.name}</span><span className="mt-1 block truncate text-xs text-muted-foreground">{optionSummary(option, inventory)}</span></span>
                <ChevronDownIcon className={'size-4 shrink-0 text-muted-foreground transition-transform ' + (open ? 'rotate-180' : '')} />
              </button>

              {open && <div id={option.id + '-editor'} className="space-y-4 bg-muted/20 px-5 pb-5 pt-1">
                <Control id={option.id + '-price'} label="Importe adicional (MXN)"><Input id={option.id + '-price'} name="price" className="h-10" type="number" min="0" step="0.01" required value={option.price} onChange={event => change(group.id, option.id, { price: event.target.value })} /></Control>
                {stock.data?.can_manage && <>
                  <Control id={option.id + '-effect'} label="Consumo de inventario"><Choice id={option.id + '-effect'} value={option.effect} options={[{ id: 'none', name: 'Conservar consumo del producto' }, ...(group.kind === 'size' ? [{ id: 'replace', name: 'Usar otra receta completa' }] : [{ id: 'add', name: 'Agregar consumo' }, { id: 'remove', name: 'Quitar consumo' }, { id: 'swap', name: 'Sustituir ingrediente' }])]} onChange={effect => change(group.id, option.id, { effect: effect as ModifierOption['effect'], target_type: effect === 'replace' ? 'recipe' : 'supply', target_id: '', quantity: '1', source_type: 'supply', source_id: '', source_quantity: '1' })} /></Control>
                  {option.effect === 'swap' ? <div className="space-y-5 border-t pt-4">
                    <p className="text-xs leading-5 text-muted-foreground">Kova retirará el ingrediente original y descontará el ingrediente elegido en su lugar.</p>
                    <InventoryTargetFields prefix={option.id + '-source'} title="Se retira de la receta base" type={option.source_type ?? 'supply'} targetId={option.source_id ?? ''} quantity={option.source_quantity ?? '1'} data={stock.data} onChange={patch => change(group.id, option.id, { source_type: patch.type, source_id: patch.targetId, source_quantity: patch.quantity })} />
                    <InventoryTargetFields prefix={option.id + '-target'} title="Se agrega en su lugar" type={option.target_type} targetId={option.target_id} quantity={option.quantity} data={stock.data} onChange={patch => change(group.id, option.id, { target_type: patch.type, target_id: patch.targetId, quantity: patch.quantity })} />
                  </div> : option.effect !== 'none' && <>
                    {option.effect !== 'replace' && <Control id={option.id + '-type'} label="Tipo"><Choice id={option.id + '-type'} value={option.target_type} options={[{ id: 'supply', name: 'Insumo' }, { id: 'recipe', name: 'Receta de venta' }]} onChange={type => change(group.id, option.id, { target_type: type as 'supply' | 'recipe', target_id: '' })} /></Control>}
                    <Control id={option.id + '-target'} label={option.target_type === 'supply' ? 'Insumo' : 'Receta'}><SearchChoice id={option.id + '-target'} value={option.target_id} options={option.target_type === 'supply' ? stock.data.supplies : stock.data.recipes.filter(recipe => recipe.type === 'recipe')} onChange={target_id => change(group.id, option.id, { target_id })} /></Control>
                    <QuantityWithUnit id={option.id + '-qty'} value={option.quantity} unit={option.target_type === 'supply' ? stock.data.supplies.find(supply => supply.id === option.target_id)?.unit : undefined} suffix={option.target_type === 'recipe' ? 'veces' : undefined} onChange={quantity => change(group.id, option.id, { quantity })} />
                  </>}
                </>}
              </div>}
            </div>
          })}
        </div>
      </section>)}

      {error && <p role="alert" className="px-5 py-4 text-sm text-destructive">{error}</p>}
    </fieldset><SheetFooter className="flex-row justify-end border-t bg-popover px-5 py-4"><Button type="button" variant="outline" onClick={close} disabled={busy}>Cancelar</Button><Button type="submit" disabled={busy}>{busy ? 'Guardando…' : 'Guardar cambios'}</Button></SheetFooter></form>
}

function selectionSummary(group: ProductModifierGroup) {
  if (group.min === 0) return group.max === 1 ? 'Opcional · máximo una opción' : `Opcional · máximo ${group.max} opciones`
  if (group.min === group.max) return group.min === 1 ? 'Una opción obligatoria' : `${group.min} opciones obligatorias`
  return `De ${group.min} a ${group.max} opciones`
}

function optionSummary(option: ModifierOption, inventory: boolean) {
  const price = Number(option.price)
  const priceText = price > 0 ? `+$${price.toFixed(2)} MXN` : 'Sin importe adicional'
  if (!inventory) return priceText
  const effectText = {
    none: 'Conserva la receta',
    add: 'Agrega consumo',
    remove: 'Quita consumo',
    replace: 'Usa otra receta',
    swap: 'Sustituye ingrediente',
  }[option.effect]
  return `${priceText} · ${effectText}`
}

function InventoryTargetFields({ prefix, title, type, targetId, quantity, data, onChange }: {
  prefix: string
  title: string
  type: 'supply' | 'recipe'
  targetId: string
  quantity: string
  data: Catalog
  onChange: (value: { type: 'supply' | 'recipe'; targetId: string; quantity: string }) => void
}) {
  const targets = type === 'supply' ? data.supplies : data.recipes.filter(recipe => recipe.type === 'recipe')
  return <section aria-labelledby={prefix + '-title'} className="space-y-3">
    <h4 id={prefix + '-title'} className="text-sm font-medium">{title}</h4>
    <Control id={prefix + '-type'} label="Tipo"><Choice id={prefix + '-type'} value={type} options={[{ id: 'supply', name: 'Insumo' }, { id: 'recipe', name: 'Receta de venta' }]} onChange={nextType => onChange({ type: nextType as 'supply' | 'recipe', targetId: '', quantity })} /></Control>
    <Control id={prefix + '-item'} label={type === 'supply' ? 'Insumo' : 'Receta'}><SearchChoice id={prefix + '-item'} value={targetId} options={targets} onChange={nextTarget => onChange({ type, targetId: nextTarget, quantity })} /></Control>
    <QuantityWithUnit id={prefix + '-quantity'} value={quantity} unit={type === 'supply' ? data.supplies.find(supply => supply.id === targetId)?.unit : undefined} suffix={type === 'recipe' ? 'veces' : undefined} onChange={nextQuantity => onChange({ type, targetId, quantity: nextQuantity })} />
  </section>
}

function QuantityWithUnit({ id, value, unit, suffix, onChange }: {
  id: string
  value: string
  unit?: Unit
  suffix?: string
  onChange: (value: string) => void
}) {
  const visibleUnit = suffix ?? unit ?? 'udm'
  const unitDescription = unit ? units[unit].label : suffix === 'veces' ? 'Veces que se aplica la receta' : 'Selecciona un insumo para ver su unidad base'
  return <Control id={id} label="Cantidad">
    <div className="relative overflow-hidden rounded-md">
      <Input id={id} name="quantity" className="h-10 pr-20" type="number" min="0.000001" step={isWholeUnit(unit) ? '1' : 'any'} inputMode={isWholeUnit(unit) ? 'numeric' : 'decimal'} required value={value} aria-describedby={id + '-unit'} onChange={event => onChange(event.target.value)} />
      <span id={id + '-unit'} title={unitDescription} className="pointer-events-none absolute inset-y-px right-px flex min-w-16 items-center justify-center rounded-r-[calc(var(--radius-md)-1px)] border-l bg-muted/50 px-3 text-sm font-medium text-muted-foreground">
        {visibleUnit}
      </span>
    </div>
  </Control>
}
