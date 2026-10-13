import { useState } from 'react'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import type { ProductModifierGroup } from '@/features/modifiers/types'
import type { PosProduct } from '../types'

export function ProductOptions({ product, close, add }: { product: PosProduct; close: () => void; add: (ids: string[]) => void }) {
  const [selected, setSelected] = useState<string[]>([])
  const groups = product.modifier_groups ?? []
  const valid = groups.every(g => { const count = g.options.filter(o => selected.includes(o.id)).length; return count >= g.min && count <= g.max })
  const clearGroup = (group: ProductModifierGroup) => {
    setSelected(current => current.filter(id => !group.options.some(option => option.id === id)))
  }

  return <Dialog open onOpenChange={open => { if (!open) close() }}><DialogContent className="flex max-h-[90dvh] flex-col sm:max-w-[500px]">
    <DialogHeader><DialogTitle>{product.name}</DialogTitle><DialogDescription>Elige las opciones para esta unidad.</DialogDescription></DialogHeader>
    <div className="min-h-0 space-y-5 overflow-y-auto">{groups.map(g => {
      const hasSelection = g.options.some(option => selected.includes(option.id))

      return <fieldset key={g.id} className="space-y-2"><legend className="text-base font-semibold">{g.name}</legend><p className="text-sm text-muted-foreground">{g.min > 0 ? 'Obligatorio' : 'Opcional'} · {g.max === 1 ? 'Elige una opción' : `Hasta ${g.max} opciones`}</p>{g.options.map(o => <label key={o.id} className="flex min-h-11 cursor-pointer items-center gap-3 border-b py-2 text-sm"><input name={g.id} type={g.max === 1 ? 'radio' : 'checkbox'} className="size-4 accent-primary" checked={selected.includes(o.id)} onChange={e => setSelected(current => e.target.checked ? [...current.filter(id => g.max !== 1 || !g.options.some(v => v.id === id)), o.id] : current.filter(id => id !== o.id))} /><span className="min-w-0 flex-1 break-words">{o.name}</span><span className="shrink-0 tabular-nums">{Number(o.price) ? '+$' + Number(o.price).toFixed(2) : 'Sin cargo'}</span></label>)}{g.min === 0 && g.max === 1 && <label className="flex min-h-11 cursor-pointer items-center gap-3 py-2 text-sm"><input name={g.id} type="radio" className="size-4 accent-primary" checked={!hasSelection} onChange={() => clearGroup(g)} /><span>Sin selección</span></label>}</fieldset>
    })}</div>
    <DialogFooter><Button variant="outline" onClick={close}>Cancelar</Button><Button disabled={!valid} onClick={() => add(selected)}>Agregar a la venta</Button></DialogFooter>
  </DialogContent></Dialog>
}
