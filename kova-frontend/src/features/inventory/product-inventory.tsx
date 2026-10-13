import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { useSession } from '@/features/auth/session-context'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { catalog, headers, mutation, type Catalog } from './workspace-api'
import { Control, Choice, SearchChoice } from './inventory-ui'
import { errorMessage } from './utils'

type Link = { type: string; target_id: string; quantity: string }

function displayQuantity(quantity: string): string {
  return quantity.includes('.')
    ? quantity.replace(/(\.\d*?[1-9])0+$/, '$1').replace(/\.0+$/, '')
    : quantity
}
export function ProductInventory({ productId, onBusyChange }: { productId: string; onBusyChange?: (busy: boolean) => void }) {
  const { activeTenant } = useSession()
  const allowed = !!activeTenant?.abilities.includes('inventory.view')
  const data = useQuery({ queryKey: ['inventory', activeTenant?.id, 'catalog'], queryFn: () => catalog(activeTenant!.id), enabled: allowed, retry: false })
  const link = useQuery({ queryKey: ['inventory', activeTenant?.id, 'product', productId], queryFn: async () => (await api.get<{ data: Link }>('/api/inventory/products/' + productId, { headers: headers(activeTenant!.id) })).data.data, enabled: allowed && !!data.data, retry: false })
  if (!activeTenant || !allowed || !data.data) return null
  if (!link.data) return <p className="px-4 text-sm text-muted-foreground">{link.isError ? 'No se pudo cargar el consumo de inventario.' : 'Cargando inventario…'}</p>
  return <ProductInventoryForm key={activeTenant.id + ':' + productId} tenant={activeTenant.id} productId={productId} initial={link.data} data={data.data} onBusyChange={onBusyChange} />
}
function ProductInventoryForm({ tenant, productId, initial, data, onBusyChange }: { tenant: string; productId: string; initial: Link; data: Catalog; onBusyChange?: (busy: boolean) => void }) {
  const cache = useQueryClient()
  const [draft, setDraft] = useState(() => ({ ...initial, quantity: displayQuantity(initial.quantity) }))
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [saved, setSaved] = useState(false)
  const options = draft.type === 'supply' ? data.supplies : data.recipes.filter(r => r.type === 'recipe')
  const selectedSupply = data.supplies.find(supply => supply.id === draft.target_id)
  const wholePiecesRequired = draft.type === 'supply' && selectedSupply?.unit === 'pz'
  const invalidPieceQuantity = wholePiecesRequired && !/^\d+$/.test(draft.quantity)
  return <section className="space-y-4 border-t px-4 py-5"><h2 className="text-base font-semibold">Consumo de inventario</h2><p className="text-sm text-muted-foreground">Se descuenta al completar la venta, por cada unidad entregada.</p>
    <fieldset disabled={busy || !data.can_manage} className="space-y-4">
      <Control id="product-inventory-type" label="Tipo de consumo"><Choice id="product-inventory-type" value={draft.type} options={[{ id: 'none', name: 'Sin control de inventario' }, { id: 'supply', name: 'Insumo directo' }, { id: 'recipe', name: 'Receta' }]} onChange={type => { setSaved(false); setDraft({ type, quantity: '1', target_id: '' }) }} /></Control>
      <p className="text-sm text-muted-foreground">{draft.type === 'recipe' ? 'Para un platillo o bebida preparada: se descuentan los ingredientes de su receta.' : draft.type === 'supply' ? 'Para algo que vendes directamente, como una botella: se descuenta del insumo seleccionado.' : 'Este producto no descontará existencias al venderse.'}</p>
      {draft.type !== 'none' && <><Control id="product-inventory-target" label={draft.type === 'supply' ? 'Insumo' : 'Receta'}><SearchChoice id="product-inventory-target" value={draft.target_id} options={options} onChange={target_id => { setSaved(false); setDraft({ ...draft, target_id }) }} /></Control>
      {!options.length && <p className="text-sm text-muted-foreground">{draft.type === 'recipe' ? 'Primero crea una receta en la sección Recetas.' : 'Primero registra un insumo en la sección Insumos.'}</p>}
      <Control id="product-inventory-quantity" label={draft.type === 'supply' ? 'Cantidad por venta (' + (selectedSupply?.unit ?? 'unidad base') + ')' : 'Veces que se aplica la receta por unidad vendida'}><Input id="product-inventory-quantity" className="h-10" type="number" min="0.000001" step={wholePiecesRequired ? '1' : 'any'} inputMode={wholePiecesRequired ? 'numeric' : 'decimal'} aria-invalid={invalidPieceQuantity} value={draft.quantity} onChange={e => { setSaved(false); setDraft({ ...draft, quantity: e.target.value }) }} />{wholePiecesRequired && <p className={invalidPieceQuantity ? 'text-xs text-destructive' : 'text-xs text-muted-foreground'}>Las piezas se registran sin decimales.</p>}</Control></>}
      {data.can_manage && <Button type="button" variant="outline" disabled={draft.type !== 'none' && (!draft.target_id || invalidPieceQuantity)} onClick={async () => { if (busy) return; setBusy(true); onBusyChange?.(true); setError(''); try { await mutation(tenant, 'products/' + productId, draft, 'PUT'); setSaved(true); await Promise.all([cache.invalidateQueries({ queryKey: ['inventory', tenant, 'product', productId] }), cache.invalidateQueries({ queryKey: ['inventory', tenant, 'products'] })]) } catch (e) { setError(errorMessage(e)) } finally { setBusy(false); onBusyChange?.(false) } }}>{busy ? 'Guardando…' : 'Guardar consumo'}</Button>}
    </fieldset>{saved && <p role="status" className="text-sm">Consumo guardado.</p>}{error && <p role="alert" className="text-sm text-destructive">{error}</p>}
  </section>
}
