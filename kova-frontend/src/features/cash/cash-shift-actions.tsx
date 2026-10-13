import { useEffect, useRef, useState, type ReactNode } from 'react'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogTitle, DialogDescription } from '@/components/ui/dialog'
import { Field, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { posCheckoutLocked } from '@/features/pos/checkout-storage'
import type { useCashOperation, CashOutcome } from './use-cash-operation'
import type { CashContextKey, CashCount, CashShift } from './types'

const denominations = [100000, 50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100, 50, 20, 10]
const money = (v: string | number) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(v))
type Draft = { lastKey?: string; version: 1; mode: 'count' | 'review' | 'done' | 'IN' | 'OUT'; total: string; method: string; quantities: Record<string, number>; reason: string; intervention: string; amount: string; count: CashCount | null; closed: CashShift | null }
const empty: Draft = { version: 1, mode: 'count', total: '', method: 'total', quantities: {}, reason: '', intervention: '', amount: '', count: null, closed: null }
type Operation = ReturnType<typeof useCashOperation>

export function CashDialog({ open, title, description, blocked, onClose, children }: { open: boolean; title: string; description: string; blocked: boolean; onClose: () => void; children: ReactNode }) {
  const heading = useRef<HTMLHeadingElement>(null)
  const returnFocus = useRef<HTMLElement | null>(null)
  useEffect(() => { if (open) heading.current?.focus() }, [open, title])
  return <Dialog open={open} onOpenChange={value => { if (!value && !blocked) onClose() }}>
    <DialogContent showCloseButton={false} className="flex h-[min(38rem,calc(100dvh-2rem))] max-w-[calc(100%-2rem)] flex-col gap-0 overflow-hidden p-0 sm:max-w-lg" onOpenAutoFocus={e => { returnFocus.current = document.activeElement instanceof HTMLElement ? document.activeElement : null; e.preventDefault(); heading.current?.focus() }} onCloseAutoFocus={e => { e.preventDefault(); if (returnFocus.current?.isConnected) returnFocus.current.focus(); else document.getElementById('cash-current-tab')?.focus() }} onInteractOutside={e => e.preventDefault()} onEscapeKeyDown={e => { if (blocked) e.preventDefault() }}>
      <div className="space-y-1 border-b px-5 py-4"><DialogTitle ref={heading} tabIndex={-1} className="text-base outline-none">{title}</DialogTitle><DialogDescription>{description}</DialogDescription></div>
      {children}
    </DialogContent>
  </Dialog>
}
function Labeled({ id, label, children }: { id: string; label: string; children: ReactNode }) { return <Field><FieldLabel htmlFor={id}>{label}</FieldLabel>{children}</Field> }

export function CashShiftActions({ shift, context, operation, canMove, onDone }: { shift: CashShift; context: CashContextKey; operation: Operation; canMove: boolean; onDone: () => void }) {
  const key = `kova:cash:flow:v1:${context.membership}:${context.tenant}:${shift.branch.id}:${shift.id}`
  const [initial] = useState(() => {
    try {
      const raw = sessionStorage.getItem(key)
      if (raw) {
        const parsed = JSON.parse(raw) as Draft
        if (parsed.version !== 1 || !['count', 'review', 'done', 'IN', 'OUT'].includes(parsed.mode) || typeof parsed.total !== 'string' || !parsed.quantities) throw new Error('No se pudo recuperar el formulario guardado.')
        const completed = operation.attempt
        if (completed?.result && completed.key !== parsed.lastKey && completed.path.includes(`/shifts/${shift.id}/`)) {
          if (completed.path.endsWith('/close')) return { draft: { ...parsed, mode: 'done', closed: completed.result as CashShift, lastKey: completed.key } as Draft, open: true, error: '' }
          if (completed.path.endsWith('/counts')) return { draft: { ...parsed, mode: 'review', count: completed.result as CashCount, lastKey: completed.key } as Draft, open: true, error: '' }
        }
        return { draft: parsed, open: true, error: '' }
      }
      const attempt = operation.attempt
      if (attempt?.path.includes(`/shifts/${shift.id}/`)) {
        const count = attempt.path.endsWith('/counts') && attempt.result ? attempt.result as CashCount : null
        const closed = attempt.path.endsWith('/close') && attempt.result ? attempt.result as CashShift : null
        if (count || !attempt.result || closed) return { draft: { ...empty, count, closed, mode: closed ? 'done' : count ? 'review' : attempt.path.endsWith('/movements') ? attempt.payload.type as 'IN' | 'OUT' : 'count' } as Draft, open: true, error: '' }
      }
      return { draft: empty, open: false, error: '' }
    } catch { return { draft: empty, open: false, error: 'No se pudo recuperar el formulario. Revisa el almacenamiento antes de continuar.' } }
  })
  const [draft, setDraft] = useState<Draft>(initial.draft)
  const [open, setOpen] = useState(initial.open)
  const [localError, setLocalError] = useState(initial.error)
  const trigger = useRef<HTMLButtonElement | null>(null)
  const uncertain = Boolean(operation.attempt && !operation.attempt.result)
  const blocked = operation.blocked
  const pendingSale = posCheckoutLocked()
  const movement = draft.mode === 'IN' || draft.mode === 'OUT'
  const title = draft.mode === 'done' ? 'Turno cerrado' : movement ? draft.mode === 'IN' ? 'Entrada de efectivo' : 'Salida de efectivo' : draft.mode === 'review' ? 'Revisa y confirma el cierre' : 'Contar efectivo'
  function update(patch: Partial<Draft>) {
    const next = { ...draft, ...patch }
    try { sessionStorage.setItem(key, JSON.stringify(next)); setDraft(next); setLocalError(''); return true }
    catch { setLocalError('No se puede guardar el formulario. Habilita el almacenamiento antes de continuar.'); return false }
  }
  function close() {
    if (blocked) return
    try { sessionStorage.removeItem(key); operation.finish(); setOpen(false); setLocalError(''); trigger.current?.focus() }
    catch { setLocalError('No se pudo guardar la salida del formulario. Intenta nuevamente.'); return }
    if (draft.mode === 'done') onDone()
  }
  function begin(mode: Draft['mode'], button: HTMLButtonElement) {
    trigger.current = button
    if (update({ ...empty, mode })) setOpen(true)
  }
  async function accept(result: CashOutcome, path: string) {
    if (!result.ok) {
      if (result.code === 'CASH_COUNT_STALE') update({ mode: 'count', count: null, total: '', quantities: {} })
      return
    }
    if (path.endsWith('/counts')) update({ mode: 'review', count: result.data as CashCount, lastKey: result.key })
    else if (path.endsWith('/close')) update({ mode: 'done', closed: result.data as CashShift, lastKey: result.key })
    else { sessionStorage.removeItem(key); setOpen(false); trigger.current?.focus() }
  }
  async function submit() {
    if (blocked || pendingSale || localError) return
    const prefix = `/api/cash/shifts/${shift.id}`
    const extra = shift.is_own ? {} : { intervention_reason: draft.intervention }
    const path = prefix + (movement ? '/movements' : draft.mode === 'review' ? '/close' : '/counts')
    const payload = movement ? { ...extra, type: draft.mode, amount: draft.amount, reason: draft.reason }
      : draft.mode === 'review' ? { ...extra, count_id: draft.count?.id, difference_reason: draft.reason }
      : { ...extra, ...(draft.method === 'total' ? { counted_cash: draft.total } : { denominations: Object.keys(draft.quantities).length ? draft.quantities : { '100000': 0 } }) }
    await accept(await operation.submit(path, payload), path)
  }
  const result = draft.mode === 'done' ? draft.closed : draft.count
  return <>
    {localError && !open && <p role="alert" className="text-sm text-destructive">{localError}</p>}
    {shift.status === 'OPEN' && <div className="flex flex-wrap gap-2 border-t pt-4">
      {canMove && <><Button variant="outline" className="h-10" disabled={blocked} onClick={e => begin('IN', e.currentTarget)}>Entrada</Button><Button variant="outline" className="h-10" disabled={blocked} onClick={e => begin('OUT', e.currentTarget)}>Salida</Button></>}
      <Button variant="destructive" className="h-10 sm:ml-auto" disabled={blocked || pendingSale} onClick={e => begin('count', e.currentTarget)}>Cerrar turno</Button>
    </div>}
    <CashDialog open={open} title={title} description={draft.mode === 'done' ? 'El resumen quedó guardado.' : movement ? 'Registra el importe y el motivo.' : draft.mode === 'review' ? 'Paso 2 de 2 · Confirma el resultado del conteo.' : 'Paso 1 de 2 · Cuenta el efectivo que hay en la caja.'} blocked={blocked} onClose={close}>
      <form className="flex min-h-0 flex-1 flex-col" onSubmit={e => { e.preventDefault(); void submit() }}>
        <div className="min-h-0 flex-1 space-y-5 overflow-y-auto p-5">
          {(localError || operation.error) && <p role="alert" className="text-sm text-destructive">{localError || operation.error}</p>}
          {pendingSale && <p role="alert" className="text-sm">Verifica la venta pendiente en Caja antes de continuar.</p>}
          <fieldset disabled={blocked || draft.mode === 'done'} className="min-w-0 space-y-5">
            {!shift.is_own && draft.mode !== 'done' && <Labeled id="intervention" label="Motivo de intervención"><Input id="intervention" className="h-10" required maxLength={1000} placeholder="Ej. Ausencia del responsable" value={draft.intervention} onChange={e => update({ intervention: e.target.value })} /></Labeled>}
            {movement && <><Labeled id="movement-amount" label="Importe (MXN)"><Input id="movement-amount" className="h-10" required type="number" min="0.01" max="999999999.99" step="0.01" placeholder="Ej. 200.00" value={draft.amount} onChange={e => update({ amount: e.target.value })} /></Labeled><Labeled id="movement-reason" label="Motivo"><Input id="movement-reason" className="h-10" required maxLength={1000} placeholder={draft.mode === 'OUT' ? 'Ej. Compra de insumos' : 'Ej. Cambio adicional'} value={draft.reason} onChange={e => update({ reason: e.target.value })} /></Labeled></>}
            {draft.mode === 'count' && <><Labeled id="count-mode" label="Forma de conteo"><Select value={draft.method} onValueChange={method => update({ method })}><SelectTrigger id="count-mode" className="h-10 data-[size=default]:h-10 w-full"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="total">Ingresar total</SelectItem><SelectItem value="denominations">Contar billetes y monedas</SelectItem></SelectContent></Select></Labeled>
              {draft.method === 'total' ? <Labeled id="counted" label="Efectivo contado (MXN)"><Input id="counted" className="h-10" required type="number" inputMode="decimal" min="0" max="999999999.99" step="0.01" placeholder="Ej. 3250.00" value={draft.total} onChange={e => update({ total: e.target.value })} /></Labeled> : <><div className="grid grid-cols-2 gap-3">{denominations.map(d => <Labeled key={d} id={`denomination-${d}`} label={money(d / 100)}><Input id={`denomination-${d}`} className="h-10" type="number" inputMode="numeric" min="0" max="1000000" step="1" placeholder="0" value={draft.quantities[d] ?? ''} onChange={e => update({ quantities: { ...draft.quantities, [d]: Number(e.target.value) } })} /></Labeled>)}</div><p>Total contado: {money(Object.entries(draft.quantities).reduce((sum, [d, n]) => sum + Number(d) * n, 0) / 100)}</p></>}
            </>}
            {(draft.mode === 'review' || draft.mode === 'done') && result && <><dl className="space-y-3">{[['Contado', result.counted_cash], ['Esperado', result.expected_cash], [Number(result.difference) < 0 ? 'Faltante' : Number(result.difference) > 0 ? 'Sobrante' : 'Sin diferencia', result.difference]].map(([label, value]) => <div key={label} className="flex justify-between gap-4"><dt>{label}</dt><dd className="font-semibold tabular-nums">{money(value ?? '0')}</dd></div>)}</dl>
              {draft.mode === 'review' && Number(result.difference) !== 0 && <Labeled id="difference-reason" label="Explicación de la diferencia"><Input id="difference-reason" className="h-10" required maxLength={1000} placeholder="Ej. Error al entregar cambio" value={draft.reason} onChange={e => update({ reason: e.target.value })} /></Labeled>}
              {draft.mode === 'review' && <p className="text-xs text-muted-foreground">El cierre es definitivo y no puede deshacerse.</p>}
            </>}
          </fieldset>
        </div>
        <div className="flex flex-wrap justify-end gap-2 border-t p-4">
          {draft.mode === 'done' ? <Button type="button" className="h-10" disabled={operation.busy} onClick={close}>Listo</Button> : uncertain && !operation.busy ? <Button type="button" className="h-10" onClick={() => { const path = operation.attempt!.path; void operation.retry().then(r => accept(r, path)) }}>{operation.attempt?.path.endsWith('/close') ? 'Verificar cierre' : 'Verificar operación'}</Button> : <>
            <Button type="button" className="h-10" variant="ghost" disabled={blocked} onClick={close}>Cancelar</Button>
            {draft.mode === 'review' && <Button type="button" className="h-10" variant="outline" disabled={blocked} onClick={() => update({ mode: 'count', count: null })}>Volver al conteo</Button>}
            <Button className="h-10" variant={draft.mode === 'review' ? 'destructive' : 'default'} disabled={blocked || pendingSale || Boolean(localError)}>{operation.busy ? draft.mode === 'review' ? 'Cerrando turno…' : movement ? 'Registrando…' : 'Revisando…' : movement ? 'Registrar movimiento' : draft.mode === 'review' ? 'Confirmar cierre' : 'Revisar conteo'}</Button>
          </>}
        </div>
      </form>
    </CashDialog>
  </>
}
