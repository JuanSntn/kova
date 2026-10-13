import { useState, type ReactNode, type FormEvent } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Field, FieldLabel } from '@/components/ui/field'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { units, emptyPresentation, equivalent, isWholeQuantity, isWholeUnit, type Supply, type Unit, type Presentation } from './types'

function Control({ id, label, children, hint }: { id: string; label: string; children: ReactNode; hint?: string }) {
  return <Field><FieldLabel htmlFor={id}>{label}</FieldLabel>{children}{hint && <p id={id + '-hint'} className="text-xs leading-5 text-muted-foreground">{hint}</p>}</Field>
}
function UnitSelect({ id, value, base, onChange }: { id: string; value: Unit; base?: Unit; onChange: (unit: Unit) => void }) {
  return <Select value={value} onValueChange={v => onChange(v as Unit)}><SelectTrigger id={id} className="h-10 w-full"><SelectValue /></SelectTrigger><SelectContent>{(Object.keys(units) as Unit[]).filter(u => !base || units[u].dimension === units[base].dimension).map(u => <SelectItem key={u} value={u}>{units[u].label}</SelectItem>)}</SelectContent></Select>
}
export function SupplyForm({ initial, onSave, onCancel, categories, taxes, saving, serverError, fieldErrors }: { categories: { id: string; name: string }[]; initial: Supply; onSave: (supply: Supply) => void; onCancel: () => void; taxes: { id: string; name: string }[]; saving: boolean; serverError: string; fieldErrors: Record<string, string[]> }) {
  const [draft, setDraft] = useState(initial)
  const [error, setError] = useState('')
  const [dirty, setDirty] = useState(false)
  function update(patch: Partial<Supply>) { setDirty(true); setDraft(d => ({ ...d, ...patch })); setError('') }
  function presentation(id: string, patch: Partial<Presentation>) { update({ presentations: draft.presentations.map(p => p.id === id ? { ...p, ...patch } : p) }) }
  function submit(e: FormEvent) {
    e.preventDefault()
    if (!draft.name.trim() || !draft.category.trim()) { setError('Completa el nombre y la categoría.'); return }
    if (isWholeUnit(draft.unit) && [draft.minimum, draft.ideal].some(value => value && !isWholeQuantity(value))) { setError('Las cantidades en piezas deben ser números enteros.'); return }
    if (draft.minimum && draft.ideal && Number(draft.ideal) < Number(draft.minimum)) { setError('El nivel ideal debe ser igual o mayor que el mínimo.'); return }
    if (draft.presentations.some(p => isWholeUnit(p.unit) && !isWholeQuantity(p.quantity))) { setError('Las cantidades en piezas deben ser números enteros.'); return }
    if (draft.presentations.some(p => !p.name.trim() || Number(p.quantity) <= 0 || units[p.unit].dimension !== units[draft.unit].dimension)) { setError('Revisa el nombre, contenido y unidad de cada presentación.'); return }
    onSave({ ...draft, name: draft.name.trim(), category: draft.category.trim() })
  }
  return <form onSubmit={submit} className="space-y-6" aria-busy={saving}>
    <fieldset disabled={saving} className="min-w-0 space-y-6">
    <div className="divide-y rounded-xl border bg-card">
      <section className="space-y-5 p-5 sm:p-6" aria-labelledby="supply-general">
        <h2 id="supply-general" className="text-base font-semibold">Datos del insumo</h2>
        <div className="grid gap-5 sm:grid-cols-2">
          <Control id="supply-name" label="Nombre"><Input autoFocus id="supply-name" className="h-10" required maxLength={150} placeholder="Ej. Coca-Cola 355 ml" value={draft.name} onChange={e => update({ name: e.target.value })} /></Control>
          <Control id="supply-category" label="Categoría" hint="Administra las categorías en la sección Categorías."><Select value={draft.category_id ?? ''} onValueChange={id => update({ category_id: id, category: categories.find(c => c.id === id)!.name })}><SelectTrigger id="supply-category" className="h-10 w-full"><SelectValue placeholder="Selecciona una categoría" /></SelectTrigger><SelectContent>{categories.map(c => <SelectItem key={c.id} value={c.id}>{c.name}</SelectItem>)}</SelectContent></Select></Control>
          <Control id="supply-origin" label="Origen"><Select value={draft.produced ? 'produced' : 'purchased'} onValueChange={v => update({ produced: v === 'produced' })}><SelectTrigger id="supply-origin" className="h-10 w-full"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="purchased">Comprado a proveedor</SelectItem><SelectItem value="produced">Producido en el negocio</SelectItem></SelectContent></Select></Control>
        </div>
        {draft.produced && <p className="text-sm text-muted-foreground">Ejemplos: masa, salsa o aderezo. La receta de producción se configurará en una fase posterior.</p>}
      </section>
      <section className="space-y-5 p-5 sm:p-6" aria-labelledby="supply-stock">
        <div><h2 id="supply-stock" className="text-base font-semibold">Unidad y niveles</h2><p className="mt-1 text-sm text-muted-foreground">La unidad base indica cómo contarás este insumo. Los niveles no agregan existencias.</p></div>
        <div className="grid gap-5 sm:grid-cols-3">
          <Control id="supply-unit" label="Unidad base"><UnitSelect id="supply-unit" value={draft.unit} onChange={unit => update({ unit, presentations: draft.presentations.map(p => units[p.unit].dimension === units[unit].dimension ? p : { ...p, unit, quantity: '' }) })} /></Control>
          <Control id="supply-min" label={'Nivel mínimo (' + draft.unit + ')'}><Input id="supply-min" className="h-10" type="number" min="0" step={isWholeUnit(draft.unit) ? '1' : 'any'} inputMode={isWholeUnit(draft.unit) ? 'numeric' : 'decimal'} placeholder="Ej. 12" value={draft.minimum} onChange={e => update({ minimum: e.target.value })} /></Control>
          <Control id="supply-ideal" label={'Nivel ideal (' + draft.unit + ')'}><Input id="supply-ideal" className="h-10" type="number" min="0" step={isWholeUnit(draft.unit) ? '1' : 'any'} inputMode={isWholeUnit(draft.unit) ? 'numeric' : 'decimal'} placeholder="Ej. 34" value={draft.ideal} onChange={e => update({ ideal: e.target.value })} /></Control>
        </div>
      </section>
      <section className="space-y-5 p-5 sm:p-6" aria-labelledby="supply-presentations">
        <div><h2 id="supply-presentations" className="text-base font-semibold">Presentaciones de compra</h2><p className="mt-1 text-sm text-muted-foreground">Indica cuánto contiene cada caja, bolsa o garrafa que compras.</p></div>
        {draft.presentations.length === 0 && <p className="text-sm text-muted-foreground">Aún no agregas presentaciones.</p>}
        <div className="divide-y">{draft.presentations.map((p, index) => <div key={p.id} className="space-y-4 py-5 first:pt-0">
          <div className="flex items-center justify-between gap-3"><h3 className="text-sm font-medium">Presentación {index + 1}</h3><Button type="button" variant="ghost" size="sm" onClick={() => update({ presentations: draft.presentations.filter(row => row.id !== p.id) })} aria-label={'Quitar presentación ' + (index + 1)}>Quitar</Button></div>
          <div className="grid gap-4 sm:grid-cols-3">
            <Control id={p.id + '-name'} label="Nombre"><Input id={p.id + '-name'} className="h-10" required maxLength={100} placeholder="Ej. Caja de 24" value={p.name} onChange={e => presentation(p.id, { name: e.target.value })} /></Control>
            <Control id={p.id + '-quantity'} label="Contenido por presentación"><Input id={p.id + '-quantity'} className="h-10" required type="number" min="0.000001" step={isWholeUnit(p.unit) ? '1' : 'any'} inputMode={isWholeUnit(p.unit) ? 'numeric' : 'decimal'} placeholder="Ej. 24" value={p.quantity} onChange={e => presentation(p.id, { quantity: e.target.value })} /></Control>
            <Control id={p.id + '-unit'} label="Unidad del contenido"><UnitSelect id={p.id + '-unit'} value={p.unit} base={draft.unit} onChange={unit => presentation(p.id, { unit })} /></Control>
          </div>
          <p className="text-sm text-muted-foreground" aria-live="polite">1 {p.name || 'presentación'} equivale a <span className="font-medium text-foreground">{equivalent(p, draft.unit)}</span> de inventario.</p>
          <details className="text-sm"><summary className="cursor-pointer rounded-sm py-2 focus-visible:outline-2 focus-visible:outline-ring">Proveedor y costo · Opcional</summary><div className="mt-3 grid gap-4 sm:grid-cols-2">
            <Control id={p.id + '-supplier'} label="Proveedor"><Input id={p.id + '-supplier'} className="h-10" maxLength={150} placeholder="Nombre del proveedor" value={p.supplier} onChange={e => presentation(p.id, { supplier: e.target.value })} /></Control>
            <Control id={p.id + '-code'} label="Código del proveedor"><Input id={p.id + '-code'} className="h-10" maxLength={100} placeholder="Ej. REF-355" value={p.supplierCode} onChange={e => presentation(p.id, { supplierCode: e.target.value })} /></Control>
            <Control id={p.id + '-price'} label="Precio sin impuesto (MXN)"><Input id={p.id + '-price'} className="h-10" type="number" min="0" step="0.01" placeholder="0.00" value={p.price} onChange={e => presentation(p.id, { price: e.target.value })} /></Control>
            <Control id={p.id + '-tax'} label="Impuesto" hint="Se guarda como referencia de compra; no calcula costos ni modifica precios de venta."><Select value={p.tax || 'none'} onValueChange={tax => presentation(p.id, { tax: tax === 'none' ? '' : tax })}><SelectTrigger id={p.id + '-tax'} className="h-10 w-full"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="none">Sin especificar</SelectItem>{taxes.map(tax => <SelectItem key={tax.id} value={tax.id}>{tax.name}</SelectItem>)}</SelectContent></Select></Control>
          </div></details>
        </div>)}</div>
        <Button type="button" variant="outline" onClick={() => update({ presentations: [...draft.presentations, emptyPresentation(draft.unit)] })}>Agregar presentación</Button>
      </section>
    </div>
    {(serverError || Object.keys(fieldErrors).length > 0) && <div role="alert" className="space-y-1 text-sm text-destructive"><p>{serverError}</p>{Object.entries(fieldErrors).map(([key, messages]) => <p key={key}>{messages.join(" ")}</p>)}</div>}
    {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
    <div className="flex flex-wrap justify-end gap-3">
      <Button type="button" variant="outline" onClick={() => { if (!dirty || window.confirm('¿Descartar los cambios de este insumo?')) onCancel() }}>Cancelar</Button>
      <Button type="submit">{saving ? 'Guardando…' : 'Guardar insumo'}</Button>
    </div>
    </fieldset>
  </form>
}
