import { useState, type ReactNode } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { isAxiosError } from 'axios'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Field as FormField, FieldLabel, FieldDescription } from '@/components/ui/field'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { CashDialog, CashShiftActions } from './cash-shift-actions'
import { Tabs, TabsContent } from '@/components/ui/tabs'
import { SectionNav, SectionNavButton } from '@/components/layout/section-nav'
import { Badge } from '@/components/ui/badge'
import { useSession } from '@/features/auth/session-context'
import { useBranch } from '@/features/branches/branch-context'
import { posCheckoutLocked } from '@/features/pos/checkout-storage'
import { cashHeaders, useCashContext } from './api'
import { readCashAttempt } from './storage'
import { useCashOperation } from './use-cash-operation'
import type { CashContextKey, CashPage as Page, CashRegister, CashShift } from './types'

const money = (value: string | number) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(value))
function localTime(value: string, timezone: string) {
  const date = new Date(value.includes('T') ? value : value.replace(' ', 'T') + 'Z')
  return new Intl.DateTimeFormat('es-MX', { dateStyle: 'short', timeStyle: 'short', timeZone: timezone }).format(date)
}
function Field({ label, id, hint, children }: { label: string; id: string; hint?: string; children: ReactNode }) {
  return <FormField className="min-w-0"><FieldLabel htmlFor={id}>{label}</FieldLabel>{children}{hint && <FieldDescription id={`${id}-hint`}>{hint}</FieldDescription>}</FormField>
}
function Message({ children }: { children: ReactNode }) { return <Alert><AlertDescription>{children}</AlertDescription></Alert> }

export function CashPage() {
  const { activeTenant } = useSession()
  const { activeBranchId } = useBranch()
  // History remains available even if the branch selector has no operable branches.
  if (!activeTenant?.membership_id) return <Message>Selecciona un negocio.</Message>
  const context = { tenant: activeTenant.id, branch: activeBranchId ?? '', membership: activeTenant.membership_id }
  return <CashWorkspace key={Object.values(context).join(':')} context={context} />
}

function CashWorkspace({ context }: { context: CashContextKey }) {
  const cache = useQueryClient()
  const [tab, setTab] = useState('current')
  const [openingDialog, setOpeningDialog] = useState(() => { try { const a = readCashAttempt(); return a?.context.tenant === context.tenant && a.context.membership === context.membership && a.path === '/api/cash/shifts' && !a.result } catch { return false } })
  const state = useCashContext(context)
  const [selected, setSelected] = useState<string | null>(() => {
    try {
      const saved = readCashAttempt()
      if (saved?.context.tenant !== context.tenant || saved.context.membership !== context.membership) return null
      return saved.path.match(/\/shifts\/([\w-]+)\//)?.[1] ?? null
    } catch { return null }
  })
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState('all')
  const [date, setDate] = useState('')
  const [registerPage, setRegisterPage] = useState(1)
  const headers = cashHeaders(context)
  const history = useQuery({ queryKey: ['cash', context.tenant, context.branch, context.membership, 'shifts', page, status, date],
    queryFn: async () => (await api.get<Page<CashShift>>('/api/cash/shifts', { headers: { ...headers, 'X-Branch-ID': '' }, params: { page, ...(status !== 'all' ? { status } : {}), ...(date ? { business_date: date } : {}) } })).data })
  const registers = useQuery({ queryKey: ['cash', context.tenant, context.branch, context.membership, 'registers', registerPage],
    queryFn: async () => (await api.get<Page<CashRegister>>('/api/cash/registers', { headers, params: { page: registerPage } })).data, enabled: Boolean(context.branch) })
  const detail = useQuery({ queryKey: ['cash', context.tenant, context.membership, 'shift', selected],
    queryFn: async () => (await api.get<{ data: CashShift }>(`/api/cash/shifts/${selected}`, { headers })).data.data, enabled: Boolean(selected) })
  const resolvedShift = selected ? detail.data : state.data?.current_shift
  const operationContext = resolvedShift ? { ...context, branch: resolvedShift.branch.id } : context
  const shiftContext = useCashContext(operationContext)
  const operation = useCashOperation(operationContext)
  const closedResult = operation.attempt?.path.endsWith('/close') && operation.attempt.result ? operation.attempt.result as CashShift : null
  // A confirmed close takes precedence over an older OPEN query response.
  const shift = closedResult && (!resolvedShift || resolvedShift.id === closedResult.id)
    ? closedResult : resolvedShift
  const [registerDialog, setRegisterDialog] = useState(false)
  const [name, setName] = useState('')
  const [registerId, setRegisterId] = useState(() => operation.attempt?.path === '/api/cash/shifts' ? String(operation.attempt.payload.register_id ?? '') : '')
  const [opening, setOpening] = useState(() => operation.attempt?.path === '/api/cash/shifts' ? String(operation.attempt.payload.opening_cash ?? '') : '')
  const [registerError, setRegisterError] = useState('')
  const [registerBusy, setRegisterBusy] = useState(false)
  async function saveRegister(data: Record<string, unknown>, id?: string) {
    if (registerBusy) return
    setRegisterBusy(true); setRegisterError('')
    try {
      if (id) await api.patch(`/api/cash/registers/${id}`, data, { headers })
      else await api.post('/api/cash/registers', data, { headers })
      setName(''); await cache.invalidateQueries({ queryKey: ['cash'] })
      if (!id) { setRegisterDialog(false); setTab('current') }
      return true
    } catch (e) { setRegisterError(isAxiosError(e) ? e.response?.data?.message ?? 'No se pudo confirmar. Actualiza el listado antes de repetir.' : 'No se pudo guardar.'); return false }
    finally { setRegisterBusy(false) }
  }
  const actions = state.data?.available_actions
  const latest = operation.attempt
  const operationStatus = tab === 'current' && latest?.result
    ? latest.path.endsWith('/close')
      ? 'Turno cerrado'
      : latest.path.endsWith('/movements')
        ? 'Movimiento registrado'
        : latest.path.endsWith('/counts')
          ? 'Conteo registrado'
          : 'Turno abierto'
    : null
  const firstBox = registers.isSuccess && registers.data.meta.total === 0
  const pageHeader = tab === 'current'
    ? { title: 'Mi turno', description: 'Controla el efectivo de tu sucursal, desde la apertura hasta el cierre de cada turno.' }
    : tab === 'history'
      ? { title: 'Historial de turnos', description: 'Consulta turnos anteriores y revisa el cierre de cada caja.' }
      : { title: 'Cajas', description: firstBox ? 'Crea la primera caja de esta sucursal para que tu equipo pueda abrir turnos.' : 'Administra las cajas disponibles en esta sucursal.' }
  const availableRegisters = registers.data?.data.filter(r => r.is_active && !r.busy) ?? []
  const selectedRegisterAvailable = availableRegisters.some(r => r.id === registerId)
  const registerManagement = actions?.manage_registers && <section aria-labelledby="cash-page-title" className="space-y-5 rounded-xl border bg-card p-5 sm:p-6">
    {registerError && !registerDialog && <p id="register-error" role="alert" className="text-sm text-destructive">{registerError}</p>}
    {!firstBox && <ul className="divide-y">{registers.data?.data.map(r => <RegisterRow key={r.id} register={r} error={registerError} disabled={registerBusy || operation.blocked} save={data => saveRegister(data, r.id)} />)}</ul>}
    <Button onClick={() => { setRegisterError(''); setRegisterDialog(true) }}>Crear caja</Button>
    <CashDialog open={registerDialog} title="Crear caja" description="Dale un nombre para identificarla en esta sucursal." blocked={registerBusy} onClose={() => setRegisterDialog(false)}>
    <form className="flex min-h-0 flex-1 flex-col" onSubmit={e => { e.preventDefault(); void saveRegister({ name }) }}>
      <div className="flex-1 space-y-4 p-5">{registerError && <p id="register-error" role="alert" className="text-sm text-destructive">{registerError}</p>}<Field id="register-name" label="Nombre de la nueva caja" hint="Por ejemplo: Caja principal, Barra o Terraza."><Input id="register-name" aria-describedby={registerError ? 'register-name-hint register-error' : 'register-name-hint'} className="h-10" placeholder="Ej. Caja principal" required maxLength={100} value={name} onChange={e => setName(e.target.value)} disabled={registerBusy || operation.blocked} /></Field>
      </div><div className="flex justify-end gap-2 border-t p-4"><Button type="button" variant="ghost" disabled={registerBusy} onClick={() => setRegisterDialog(false)}>Cancelar</Button><Button className="h-10" disabled={registerBusy || operation.blocked || !name.trim()}>{registerBusy ? 'Guardando…' : 'Crear caja'}</Button></div>
    </form></CashDialog>
    {firstBox && <p className="text-xs text-muted-foreground">Crear una caja no abre un turno ni la asigna a una persona. El cajero asignado a esta sucursal debe entrar con su cuenta y abrir su propio turno.</p>}
  </section>
  return <div className="w-full min-w-0">
    <Tabs value={tab} className="gap-6">
    <SectionNav label="Secciones de caja">
      <SectionNavButton id="cash-current-tab" active={tab === 'current'} disabled={operation.blocked} onClick={() => { setTab('current'); setSelected(null) }}>Mi turno</SectionNavButton>
      <SectionNavButton active={tab === 'history'} disabled={operation.blocked} onClick={() => { setTab('history'); setSelected(null) }}>Historial</SectionNavButton>
      {actions?.manage_registers && <SectionNavButton active={tab === 'registers'} disabled={operation.blocked} onClick={() => { setTab('registers'); setSelected(null) }}>Cajas</SectionNavButton>}
    </SectionNav>
    <header className="flex items-start justify-between gap-4">
      <div className="min-w-0"><h1 id="cash-page-title" className="text-xl font-semibold tracking-tight">{pageHeader.title}</h1><p className="mt-1 max-w-2xl text-sm text-muted-foreground">{pageHeader.description}</p></div>
      {operationStatus && <Badge role="status" variant="outline" className="mt-0.5 shrink-0 font-normal">{operationStatus}</Badge>}
    </header>
    {operation.error && !shift && !openingDialog && <div role="alert"><Message>{operation.error}</Message></div>}
    {latest && !latest.result && !operation.busy && !shift && !openingDialog && <Message><p>Hay una operación pendiente de verificar. Conservamos su clave y contenido.</p><Button className="mt-3" onClick={() => void operation.retry()}>Verificar operación</Button></Message>}
    {(state.isError || registers.isError || history.isError || detail.isError) && <Message>No se pudo cargar la información. <Button variant="outline" onClick={() => void cache.invalidateQueries({ queryKey: ['cash'] })}>Reintentar</Button></Message>}
    {state.isLoading && context.branch && <p role="status">Consultando caja…</p>}
    {state.data?.closure_required && <Message>Tu contrato ya no incluye caja. Cierra los turnos pendientes antes de continuar con nuevas ventas.</Message>}
    {posCheckoutLocked() && <Message>Hay una venta pendiente de verificar en esta pestaña. Resuélvela en Caja antes de contar o cerrar.</Message>}
    <TabsContent value="current" className="-mt-1 space-y-5 empty:hidden">
    {firstBox && actions?.manage_registers && <section className="space-y-3 rounded-xl border bg-card p-5"><h2 className="text-base font-semibold">Crea tu primera caja</h2><p className="text-sm text-muted-foreground">Crea una caja para que tu equipo pueda abrir su turno.</p><Button onClick={() => { setTab('registers'); setRegisterDialog(true) }}>Crear caja</Button></section>}
    {(openingDialog || (!selected && actions?.open && !firstBox && shift?.status !== 'OPEN')) && <section className="space-y-5 rounded-xl border bg-card p-5 sm:p-6" aria-labelledby="open-shift-title">
      <h2 id="open-shift-title" className="text-base font-semibold">Sin turno abierto</h2>
      <p className="text-sm text-muted-foreground">Abre tu turno con el efectivo inicial para comenzar a cobrar.</p>
      <Button disabled={operation.blocked || !availableRegisters.length} onClick={() => setOpeningDialog(true)}>Abrir turno</Button>
      <CashDialog open={openingDialog} title="Abrir mi turno" description="Selecciona una caja y cuenta el efectivo inicial." blocked={operation.blocked} onClose={() => setOpeningDialog(false)}>
      <form className="flex min-h-0 flex-1 flex-col" onSubmit={e => { e.preventDefault(); if (selectedRegisterAvailable) void operation.submit('/api/cash/shifts', { register_id: registerId, opening_cash: opening }).then(result => { if (result.ok) setOpeningDialog(false) }) }}>
        <div className="min-h-0 flex-1 space-y-5 overflow-y-auto p-5">
        {operation.error && <p role="alert" className="text-sm text-destructive">{operation.error}</p>}
        <Field id="register" label="Caja" hint="Sólo aparecen cajas activas y sin un turno abierto."><Select value={registerId} onValueChange={setRegisterId} disabled={operation.blocked || registers.isPending || availableRegisters.length === 0}><SelectTrigger id="register" aria-describedby="register-hint" className="h-10 data-[size=default]:h-10 w-full"><SelectValue placeholder={registers.isPending ? 'Cargando cajas…' : availableRegisters.length ? 'Selecciona una caja' : 'Sin cajas disponibles'} /></SelectTrigger><SelectContent>{availableRegisters.map(r => <SelectItem key={r.id} value={r.id}>{r.name}</SelectItem>)}</SelectContent></Select></Field>
        <Field id="opening" label="Fondo inicial (MXN)" hint="Cuenta el efectivo que ya está en la caja para dar cambio. Si no hay, escribe 0."><Input id="opening" aria-describedby="opening-hint" placeholder="Ej. 1000.00" inputMode="decimal" className="h-10" type="number" min="0" max="999999999.99" step="0.01" required value={opening} onChange={e => setOpening(e.target.value)} disabled={operation.blocked} /></Field>
        </div><div className="flex justify-end gap-2 border-t p-4"><Button type="button" variant="ghost" disabled={operation.blocked} onClick={() => setOpeningDialog(false)}>Cancelar</Button>
        {operation.attempt && !operation.attempt.result && !operation.busy ? <Button type="button" onClick={() => void operation.retry().then(r => { if (r.ok) setOpeningDialog(false) })}>Verificar operación</Button> : <Button className="h-10" disabled={operation.blocked || !selectedRegisterAvailable || opening === ''}>{operation.busy ? 'Abriendo turno…' : 'Abrir turno'}</Button>}</div>
      </form></CashDialog>
      {registers.isSuccess && availableRegisters.length === 0 && <p className="rounded-md bg-muted/50 p-3 text-sm">{registers.data.meta.last_page > 1 ? 'No hay cajas disponibles en esta página. Revisa las demás cajas con los controles de paginación.' : 'No hay cajas libres y activas. Si el dueño abrió un turno con su cuenta, debe cerrarlo con su conteo antes de que tú abras el tuyo. No puedes utilizar el turno de otra persona.'}</p>}
    </section>}
    {firstBox && !actions?.manage_registers && <Message>Aún no hay cajas en esta sucursal. Pide al dueño que cree una para poder abrir tu turno.</Message>}
    </TabsContent>
    {shift && ((tab === 'history' && selected) || tab === 'current') && <section className="-mt-1 space-y-5 rounded-xl border bg-card p-5 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3"><div><h2 className="text-base font-semibold">{shift.register.name}</h2><p className="text-sm text-muted-foreground">{shift.branch.name} · {shift.responsible} · {shift.business_date}</p></div><Badge variant="outline">{shift.status === 'OPEN' ? 'Abierto' : 'Cerrado'}</Badge></div>
      <dl className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-3"><div><dt className="text-muted-foreground">Fondo inicial</dt><dd className="font-medium tabular-nums">{money(shift.opening_cash)}</dd></div><div><dt className="text-muted-foreground">Ventas</dt><dd>{shift.sales_count}</dd></div><div><dt className="text-muted-foreground">Apertura</dt><dd>{localTime(shift.opened_at, shift.opened_timezone)} ({shift.opened_timezone})</dd></div></dl>
      {shift.expected_cash !== undefined && <Summary shift={shift} />}
      {shiftContext.data?.permissions.operate && <CashShiftActions key={shift.id} shift={shift} context={operationContext} operation={operation} canMove={Boolean(shiftContext.data?.feature_enabled && (shift.is_own ? shiftContext.data.available_actions.move : shiftContext.data.available_actions.supervise_move))} onDone={() => { operation.finish(); setSelected(null); setTab('current'); requestAnimationFrame(() => document.getElementById('cash-current-tab')?.focus()) }} />}
      <div className="border-t pt-4"><h3 className="text-sm font-semibold">Movimientos</h3>{shift.movements.length === 0 ? <p className="mt-2 text-sm text-muted-foreground">Sin movimientos manuales.</p> : <ul className="divide-y">{shift.movements.map(m => <li key={m.id} className="flex justify-between gap-4 py-3 text-sm"><div className="min-w-0 break-words"><p>{m.reason}</p><p className="text-xs text-muted-foreground">{m.author} · {localTime(m.created_at, shift.opened_timezone)}</p></div><span className="shrink-0 tabular-nums">{m.type === 'IN' ? '+' : '−'}{money(m.amount)}</span></li>)}</ul>}</div>
      {selected && <Button variant="outline" onClick={() => { setSelected(null); setTab('current') }}>Volver a mi turno</Button>}
    </section>}
    <TabsContent value="registers" className="-mt-1 space-y-5 empty:hidden">{registerManagement}
    {registers.data && registers.data.meta.last_page > 1 && <div className="flex gap-3"><Button variant="outline" disabled={registerPage === 1} onClick={() => setRegisterPage(p => p - 1)}>Cajas anteriores</Button><Button variant="outline" disabled={registerPage >= registers.data.meta.last_page} onClick={() => setRegisterPage(p => p + 1)}>Más cajas</Button></div>}
    </TabsContent>
    <TabsContent value="history" className="-mt-1">{selected && detail.isPending && <p role="status" className="py-4 text-sm">Cargando turno…</p>}<section aria-labelledby="cash-page-title" className="space-y-5 rounded-xl border bg-card p-5 sm:p-6">
      <div className="grid max-w-2xl gap-4 sm:grid-cols-2"><Field label="Estado" id="filter-status"><Select value={status} onValueChange={s => { setStatus(s); setPage(1) }}><SelectTrigger id="filter-status" className="h-10 data-[size=default]:h-10 w-full"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="all">Todos</SelectItem><SelectItem value="OPEN">Abiertos</SelectItem><SelectItem value="CLOSED">Cerrados</SelectItem></SelectContent></Select></Field><Field id="filter-date" label="Fecha operativa"><Input id="filter-date" className="h-10" type="date" value={date} onChange={e => { setDate(e.target.value); setPage(1) }} /></Field></div>
      {history.isLoading && <p role="status">Cargando turnos…</p>}
      {history.data?.data.length === 0 && <p className="rounded-md bg-muted/40 px-4 py-6 text-center text-sm text-muted-foreground">{status !== 'all' || date ? 'No hay turnos que coincidan. Prueba otra fecha o muestra todos los estados.' : 'Aquí aparecerán los turnos de tu equipo cuando comiencen a operar.'}</p>}
      <ul className="divide-y">{history.data?.data.map(s => <li key={s.id} className="flex flex-wrap items-center justify-between gap-3 py-3"><div className="min-w-0 text-sm"><p className="font-medium">{s.register.name} · {s.responsible}</p><p className="text-muted-foreground">{s.branch.name} · {s.business_date} · {s.status === 'OPEN' ? 'Abierto' : 'Cerrado'}</p></div><Button variant="outline" disabled={operation.blocked} onClick={() => { setSelected(s.id) }}>Ver turno</Button></li>)}</ul>
      {history.data && history.data.meta.last_page > 1 && <div className="flex gap-3"><Button variant="outline" disabled={page === 1} onClick={() => setPage(p => p - 1)}>Anterior</Button><Button variant="outline" disabled={page >= history.data.meta.last_page} onClick={() => setPage(p => p + 1)}>Siguiente</Button></div>}
    </section></TabsContent></Tabs>
  </div>
}

function Summary({ shift }: { shift: CashShift }) {
  return <div className="space-y-3 border-t pt-4"><dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">{[
    ['Efectivo vendido', shift.cash_sales_total], ['Tarjeta', shift.card_sales_total], ['Transferencia', shift.transfer_sales_total], ['Entradas', shift.cash_in_total], ['Salidas', shift.cash_out_total], ['Efectivo esperado', shift.expected_cash], ['Efectivo contado', shift.counted_cash], ['Diferencia', shift.difference],
  ].map(([label, value]) => value !== undefined && <div key={label}><dt className="text-muted-foreground">{label}</dt><dd className="font-medium tabular-nums">{money(value)}</dd></div>)}</dl>{shift.difference_reason && <p className="text-sm">Motivo: {shift.difference_reason}</p>}{shift.closed_by && <p className="text-sm text-muted-foreground">Cerrado por {shift.closed_by} · {shift.closed_at && localTime(shift.closed_at, shift.opened_timezone)}</p>}{shift.intervention_reason && <p className="text-sm">Intervención: {shift.intervention_reason}</p>}</div>
}

function RegisterRow({ register, error, disabled, save }: { register: CashRegister; error: string; disabled: boolean; save: (data: Record<string, unknown>) => Promise<boolean | undefined> }) {
  const [editing, setEditing] = useState(false)
  const [name, setName] = useState(register.name)
  return <li className="space-y-3 py-3 text-sm"><div className="flex flex-wrap items-center justify-between gap-3"><span>{register.name} · {register.busy ? 'En uso' : register.is_active ? 'Disponible' : 'Inactiva'}</span><div className="flex gap-2"><Button variant="outline" size="sm" disabled={disabled} onClick={() => setEditing(v => !v)}>Cambiar nombre</Button><Button variant="outline" size="sm" disabled={disabled || register.busy} onClick={() => void save({ is_active: !register.is_active })}>{register.is_active ? 'Desactivar' : 'Activar'}</Button></div></div>
    <CashDialog open={editing} title="Cambiar nombre de caja" description="El historial de esta caja se conserva." blocked={disabled} onClose={() => setEditing(false)}>
      <form className="flex min-h-0 flex-1 flex-col" onSubmit={e => { e.preventDefault(); void save({ name }).then(saved => { if (saved) setEditing(false) }) }}>
        <div className="flex-1 space-y-4 p-5">{error && <p role="alert" className="text-sm text-destructive">{error}</p>}<Field id={`name-${register.id}`} label="Nombre de caja"><Input placeholder="Ej. Caja de barra" className="h-10" id={`name-${register.id}`} value={name} required maxLength={100} onChange={e => setName(e.target.value)} /></Field></div>
        <div className="flex justify-end gap-2 border-t p-4"><Button type="button" variant="ghost" disabled={disabled} onClick={() => setEditing(false)}>Cancelar</Button><Button disabled={disabled}>Guardar nombre</Button></div>
      </form>
    </CashDialog>
  </li>
}
