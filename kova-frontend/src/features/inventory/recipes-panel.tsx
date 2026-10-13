import { useState, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription, SheetFooter } from '@/components/ui/sheet'
import { Input } from '@/components/ui/input'
import { ChevronDown } from 'lucide-react'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Table, TableHeader, TableHead, TableBody, TableRow, TableCell } from '@/components/ui/table'
import { Control, Choice, SearchChoice } from './inventory-ui'
import { errorMessage, quantity } from './utils'
import { isWholeUnit, units, type Unit } from './types'
import { mutation, type Catalog, type Recipe, type RecipeType } from './workspace-api'

const recipeLabels = { recipe: 'Receta', production: 'Receta de producción', subrecipe: 'Subreceta' }
export function RecipesPanel({ tenant, data }: { tenant: string; data: Catalog }) {
  const client = useQueryClient()
  const [draft, setDraft] = useState<Recipe | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const close = () => { if (!busy) setDraft(null) }
  let form: ReactNode = null
  if (draft) {
    const update = (patch: Partial<Recipe>) => setDraft({ ...draft, ...patch })
    form = <form className="flex h-full min-h-0 flex-col" onSubmit={async e => {
      e.preventDefault(); if (busy) return; setBusy(true); setError('')
      try {
        // The recipe UUID belongs in the URL. The backend derives tenant and
        // author from the authenticated request and rejects client scope IDs.
        const { id, ...payload } = draft
        await mutation(tenant, 'recipes' + (id ? '/' + id : ''), payload, id ? 'PUT' : 'POST')
        await client.invalidateQueries({ queryKey: ['inventory'] })
        setDraft(null)
      } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) }
    }}>
      <SheetHeader className="pr-12"><SheetTitle>{draft.id ? 'Editar' : 'Nueva'} {recipeLabels[draft.type].toLowerCase()}</SheetTitle><SheetDescription>{draft.type === 'production' ? 'Define el preparado que obtienes y los ingredientes que consumes.' : draft.type === 'subrecipe' ? 'Agrupa ingredientes reutilizables. No genera existencias propias.' : 'Define los ingredientes para una unidad de venta.'}</SheetDescription></SheetHeader>
      <fieldset disabled={busy || !data.can_manage} className="min-h-0 min-w-0 flex-1 space-y-6 overflow-y-auto px-4 py-6">
        <section className="space-y-5">
          <Control id="recipe-name" label="Nombre"><Input id="recipe-name" required maxLength={150} value={draft.name} onChange={e => update({ name: e.target.value })} placeholder={draft.type === 'production' ? 'Ej. Masa para pizza' : draft.type === 'subrecipe' ? 'Ej. Mezcla de toppings' : 'Ej. Pizza pepperoni'} /></Control>
          {draft.type === 'production' && <Control id="recipe-output" label="Insumo producido"><Choice id="recipe-output" value={draft.output_supply_id ?? ''} options={data.supplies.filter(s => s.produced)} onChange={id => update({ output_supply_id: id, yield_unit: data.supplies.find(s => s.id === id)!.unit })} /></Control>}
          {draft.type !== 'recipe' && <div className="grid items-end gap-4 sm:grid-cols-2"><Control id="recipe-yield" label={draft.type === 'production' ? 'Cantidad producida por lote' : 'Cantidad de referencia'}><Input id="recipe-yield" required type="number" min="0.000001" step={isWholeUnit(draft.yield_unit) ? '1' : 'any'} inputMode={isWholeUnit(draft.yield_unit) ? 'numeric' : 'decimal'} value={draft.yield_quantity} onChange={e => update({ yield_quantity: e.target.value })} /></Control><Control id="recipe-unit" label="Unidad del resultado"><Choice id="recipe-unit" value={draft.yield_unit} options={Object.entries(units).filter(([key]) => draft.type !== 'production' || !draft.output_supply_id || units[key as Unit].dimension === units[data.supplies.find(s => s.id === draft.output_supply_id)!.unit].dimension).map(([id, u]) => ({ id, name: u.label }))} onChange={unit => update({ yield_unit: unit as Unit })} /></Control></div>}
        </section>
        <section className="space-y-5 border-t pt-6"><h2 className="text-base font-semibold">Componentes</h2>
          {draft.components.map((c, index) => {
            const options = c.type === 'supply' ? data.supplies : data.recipes.filter(r => r.type === 'subrecipe' && r.id !== draft.id)
            const change = (patch: Partial<typeof c>) => update({ components: draft.components.map((row, i) => i === index ? { ...row, ...patch } : row) })
            return <div key={index} className="grid items-end gap-x-6 gap-y-4 border-b pb-6 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
              <Control id={'component-type-' + index} label="Tipo"><Choice id={'component-type-' + index} value={c.type} options={[{ id: 'supply', name: 'Insumo' }, { id: 'subrecipe', name: 'Subreceta' }]} onChange={v => change({ type: v as typeof c.type, id: '' })} /></Control>
              <Control id={'component-id-' + index} label="Componente"><SearchChoice id={'component-id-' + index} value={c.id} options={options} onChange={id => { const target = options.find(o => o.id === id)!; change({ id, unit: 'unit' in target ? target.unit : target.yield_unit }) }} /></Control>
              <Control id={'component-quantity-' + index} label={'Cantidad (' + c.unit + ')'}><Input id={'component-quantity-' + index} required type="number" min="0.000001" step={isWholeUnit(c.unit) ? '1' : 'any'} inputMode={isWholeUnit(c.unit) ? 'numeric' : 'decimal'} value={c.quantity} onChange={e => change({ quantity: e.target.value })} /></Control>
              <Button type="button" variant="ghost" onClick={() => update({ components: draft.components.filter((_, i) => i !== index) })}>Quitar</Button>
            </div>
          })}
          <div className="flex flex-wrap gap-3"><Button type="button" variant="outline" onClick={() => update({ components: [...draft.components, { type: 'supply', id: '', quantity: '', unit: 'pz' }] })}>Agregar insumo</Button><Button type="button" variant="outline" onClick={() => update({ components: [...draft.components, { type: 'subrecipe', id: '', quantity: '', unit: 'pz' }] })}>Asociar subreceta</Button></div>
        </section>
        {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
      </fieldset>
      <SheetFooter className="shrink-0 border-t px-4 py-4">
        <Button type="button" variant="outline" disabled={busy} onClick={close}>{data.can_manage ? 'Cancelar' : 'Volver'}</Button>
        {data.can_manage && <Button disabled={busy || !draft.components.length || draft.components.some(c => !c.id)} type="submit">{busy ? 'Guardando…' : 'Guardar receta'}</Button>}
      </SheetFooter>
    </form>
  }
  return <div className="space-y-6">
    <div className="flex justify-between gap-4"><h1 className="text-xl font-semibold">Recetas</h1>{data.can_manage && <DropdownMenu>
      <DropdownMenuTrigger asChild><Button>Agregar receta<ChevronDown aria-hidden="true" className="size-4" /></Button></DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-56">
        {(Object.keys(recipeLabels) as RecipeType[]).map(type => <DropdownMenuItem key={type} onSelect={() => { setError(''); setDraft({ name: '', type, yield_quantity: '1', yield_unit: 'pz', output_supply_id: null, components: [] }) }}>{recipeLabels[type]}</DropdownMenuItem>)}
      </DropdownMenuContent>
    </DropdownMenu>}</div>
    {data.recipes.length ? <div className="overflow-hidden rounded-xl border"><Table><TableHeader><TableRow className="bg-muted/40"><TableHead className="pl-5">Nombre</TableHead> <TableHead>Tipo</TableHead><TableHead>Resultado / referencia</TableHead><TableHead><span className="sr-only">Acciones</span></TableHead></TableRow></TableHeader><TableBody>{data.recipes.map(r => <TableRow key={r.id}><TableCell className="py-4 pl-5">{r.name}</TableCell><TableCell>{recipeLabels[r.type]}</TableCell><TableCell>{quantity(r.yield_quantity)} {r.yield_unit}</TableCell><TableCell className="text-right"><Button variant="ghost" onClick={() => { setError(''); setDraft(r) }}>{data.can_manage ? 'Editar' : 'Consultar'}</Button></TableCell></TableRow>)}</TableBody></Table></div> : <p className="py-12 text-center text-sm text-muted-foreground">Crea una receta, un preparado de producción o una subreceta.</p>}
    <Sheet open={!!draft} onOpenChange={open => { if (!open) close() }}>
      <SheetContent side="right" className="data-[side=right]:w-full data-[side=right]:sm:max-w-[500px]" onInteractOutside={e => { if (busy) e.preventDefault() }} onEscapeKeyDown={e => { if (busy) e.preventDefault() }}>
        {form}
      </SheetContent>
    </Sheet>
  </div>
}
