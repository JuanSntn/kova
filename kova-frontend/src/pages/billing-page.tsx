import axios from 'axios'
import { useEffect, useRef, useState } from 'react'
import { toast } from 'sonner'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useSearch } from '@tanstack/react-router'
import { SectionNav, SectionNavButton } from '@/components/layout/section-nav'
import { useBillingSummary } from '@/features/billing/use-billing-summary'
import { PageHeader } from '@/components/layout/page-header'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { useSession } from '@/features/auth/session-context'
import { queryKeys } from '@/lib/query-keys'
import { BillingDetailsPage } from '@/pages/billing/billing-details-page'
import { PlansPage } from '@/pages/billing/plans-page'
import { CommercialSummary } from '@/features/billing/commercial-summary'
import { money, date } from '@/features/billing/presentation'
import { addSubscriptionAddon, cancelPendingChange, cancelPendingCheckout, cancelSubscription, createBillingPortal, createCheckout, getBillingOffers, reactivateSubscription, reconcileBilling, reduceSubscriptionAddon, type AddonOffer, type BillingPlan, type BillingSummary, type OfferPrice, type SubscriptionAddon } from '@/features/billing/api'

type Intent = { title: string; description: string; branchLimit: number | null; userLimit: number | null; operationId: string } & (
  | { kind: 'plan'; plan: BillingPlan; price: OfferPrice }
  | { kind: 'cancel' | 'reactivate' }
  | { kind: 'cancel-change'; id: string }
  | { kind: 'addon'; offer: AddonOffer; quantity: number; existing?: SubscriptionAddon }
)
export function BillingPage() {
  const { activeTenant } = useSession()
  return <TenantBilling key={activeTenant?.id} />
}
function TenantBilling() {
  const { activeTenant } = useSession()
  const tenantId = activeTenant?.id ?? ''
  const navigate = useNavigate()
  const search = useSearch({ strict: false }) as { view?: string; checkout?: string; change?: string }
  const view = search.view === 'plans' ? 'plans' : 'details'
  const client = useQueryClient()
  const [pending, setPending] = useState<string | null>(null)
  const busy = useRef(false)
  const [intent, setIntent] = useState<Intent | null>(null)
  const [retained, setRetained] = useState<string[]>([])
  const [retainedUsers, setRetainedUsers] = useState<string[]>([])
  const [error, setError] = useState('')
  const [paymentUrl, setPaymentUrl] = useState<string | null>(null)
  const [checkoutError, setCheckoutError] = useState('')
  const [portalError, setPortalError] = useState('')
  const handledCheckoutReturn = useRef('')
  const { query, restart, paused, hasPending } = useBillingSummary(tenantId)
  const offersQuery = useQuery({ queryKey: [...queryKeys.billing(tenantId), 'offers'], queryFn: getBillingOffers, enabled: Boolean(tenantId) })
  const summary = query.data
  const canManage = Boolean(summary?.actions.can_manage)
  const disabled = pending !== null || !canManage
  function choose(next: Omit<Intent, 'operationId'> | Intent) {
    setIntent({ ...next, operationId: crypto.randomUUID() } as Intent)
    setRetained([])
    setRetainedUsers(summary?.active_memberships.filter(member => member.is_owner).map(member => member.id) ?? [])
    setError('')
  }
  function showPlans() { void navigate({ to: '/$tenantSlug/billing', params: { tenantSlug: activeTenant?.slug ?? '' }, search: { view: 'plans', checkout: undefined, change: undefined } }) }
  async function refresh() {
    restart()
    await client.invalidateQueries({ queryKey: queryKeys.billing(tenantId) })
    await client.invalidateQueries({ queryKey: queryKeys.branches(tenantId) })
    await client.invalidateQueries({ queryKey: ['navigation', tenantId] })
  }
  async function reconcile() {
    if (busy.current || !canManage) return
    busy.current = true; setPending('reconcile'); setCheckoutError(''); restart()
    try { await reconcileBilling(); await refresh() } catch (e) { const message = errorMessage(e); setCheckoutError(message); toast.error(message); await refresh() } finally { busy.current = false; setPending(null) }
  }
  async function cancelCheckoutAttempt() {
    if (busy.current || !canManage) return
    busy.current = true; setPending('cancel-checkout'); setCheckoutError('')
    try { await cancelPendingCheckout(); toast.success('Pago cancelado. No se realizó ningún cargo. Puedes elegir otro plan cuando quieras.'); await refresh() } catch (e) { const message = errorMessage(e); setCheckoutError(message); toast.error(message); await refresh() } finally { busy.current = false; setPending(null) }
  }
  async function portal() {
    if (busy.current || !canManage) return
    busy.current = true; setPending('portal'); setPortalError(''); restart()
    try { window.location.assign((await createBillingPortal()).url) } catch (e) { const message = errorMessage(e); setPortalError(message); toast.error(message) } finally { busy.current = false; setPending(null) }
  }
  function selectPlan(plan: BillingPlan, price: OfferPrice) {
    const limit = plan.version === summary?.contract?.version ? summary.entitlements.included_branches : plan.included_branches
    const userLimit = plan.version === summary?.contract?.version ? summary.entitlements.max_users : plan.max_users
    choose({ kind: 'plan', plan, price, title: `Cambiar a ${plan.name}`, branchLimit: typeof limit === 'number' ? limit : null, userLimit: typeof userLimit === 'number' ? userLimit : null,
      description: `${money(price.amount, price.currency)} por ${price.interval === 'year' ? 'año' : 'mes'}, más impuestos aplicables. ${summary?.current_subscription ? `Se aplicará el ${date(summary.current_subscription.current_period_ends_at)}, sin reiniciar tus cupos hoy. Al cambiar de versión se retiran los complementos recurrentes anteriores.` : 'La contratación se activará después de confirmar el pago y preparar el cobro de extras.'}`,
    } as Intent)
  }
  function selectAddon(offer: AddonOffer, quantity: number, existing?: SubscriptionAddon) {
    const reducing = quantity < (existing?.quantity ?? 0)
    const limit = summary?.entitlements.included_branches
    const price = offer.prices.find(p => p.offer_id === existing?.offer_id) ?? offer.prices[0]
    choose({ kind: 'addon', offer, quantity, existing, title: `${reducing ? 'Reducir' : 'Contratar'} ${offer.name}`,
      branchLimit: reducing && typeof limit === 'number' ? limit - (existing?.quantity ?? 0) + quantity : null,
      userLimit: null,
      description: `${quantity} unidades en total. ${money(quantity * (existing?.amount ?? price.amount), price.currency)} por mes, más impuestos. ${reducing ? `La reducción se aplicará al cierre de extras, el ${date(summary?.billing_components.find(c => c.role === 'extras')?.next_billing_at)}.` : 'Se genera un cargo proporcional por el tiempo restante del mes. El aumento solo se habilita tras confirmar el pago.'}`,
    } as Intent)
  }
  async function resume(p: BillingSummary['pending_contracts'][number]) {
    if (busy.current || !canManage) return
    busy.current = true; setPending('checkout'); setCheckoutError(''); restart()
    try {
      const response = await createCheckout(p.plan, p.interval, p.offer_id)
      const target = new URL(response.url, window.location.origin)
      if (target.origin === window.location.origin) { await refresh() } else { window.location.assign(response.url) }
    } catch (e) { const message = errorMessage(e); setCheckoutError(message); toast.error(message); await refresh() } finally { busy.current = false; setPending(null) }
  }
  async function confirm() {
    if (!intent || busy.current || !canManage) return
    busy.current = true; setPending(intent.kind); setError(''); restart()
    try {
      switch (intent.kind) {
        case 'plan': {
          const response = await createCheckout(intent.plan.code, intent.price.interval, intent.price.offer_id, retained, retainedUsers, intent.operationId)
          const target = new URL(response.url, window.location.origin)
          if (target.origin === window.location.origin) { toast.success('Cambio solicitado. Consulta su estado en cambios programados.') } else { window.location.assign(response.url) }
          break
        }
        case 'cancel': await cancelSubscription(retained, retainedUsers); toast.success('Cancelación programada al finalizar el contrato.'); break
        case 'reactivate': await reactivateSubscription(); toast.success('Cancelación revertida.'); break
        case 'cancel-change': await cancelPendingChange(intent.id); toast.success('Cambio cancelado.'); break
        case 'addon': {
          if (intent.existing && intent.quantity < intent.existing.quantity) { await reduceSubscriptionAddon(intent.existing.id, intent.quantity, retained); toast.success('Reducción solicitada. Consulta su fecha efectiva.') }
          else {
            const price = intent.offer.prices.find(p => p.offer_id === intent.existing?.offer_id) ?? intent.offer.prices[0]
            const result = await addSubscriptionAddon(price.offer_id, intent.quantity, intent.operationId)
            setPaymentUrl(result.invoice_url ?? null)
            toast.info(result.status === 'confirmed' ? 'Pago confirmado. Actualizando los complementos.' : 'El complemento está pendiente de pago.')
          }
        }
      }
      setIntent(null)
      await refresh()
    } catch (e) { setError(errorMessage(e)); await refresh() } finally { busy.current = false; setPending(null) }
  }
  useEffect(() => {
    if (!search.checkout) {
      handledCheckoutReturn.current = ''
      return
    }
    if (!tenantId || !canManage || handledCheckoutReturn.current === search.checkout) return
    handledCheckoutReturn.current = search.checkout
    const cleanUrl = async () => {
      await navigate({ to: '/$tenantSlug/billing', params: { tenantSlug: activeTenant?.slug ?? '' }, search: { view, checkout: undefined, change: undefined } })
    }
    if (search.checkout === 'cancelled') {
      void (async () => {
        await cancelCheckoutAttempt()
        await cleanUrl()
      })()
      return
    }
    if (search.checkout === 'success') {
      void (async () => {
        await reconcile()
        await cleanUrl()
      })()
    }
  }, [tenantId, canManage, search.checkout])
  if (query.isPending) return <><PageHeader title="Facturación" description="Contrato, consumo y pagos." /><Skeleton className="h-80" /></>
  if (!summary) return <Alert variant="destructive"><AlertDescription>No pudimos cargar la facturación.<Button variant="link" onClick={() => void refresh()}>Reintentar</Button></AlertDescription></Alert>
  const needsBranches = intent?.branchLimit !== null && intent?.branchLimit !== undefined && summary.branches.length > intent.branchLimit
  const needsUsers = intent?.userLimit !== null && intent?.userLimit !== undefined && summary.active_memberships.length > intent.userLimit
  const hasManualReconciliation = summary.pending_contracts.some(contract => contract.state === 'reconciliation_required')
  const hasBlockingPendingContract = summary.pending_contracts.some(contract => contract.state !== 'awaiting_customer')
  return <>
    <PageHeader title="Facturación" description="Contrato, consumo y pagos de tu negocio." actions={summary.stripe.customer_exists && canManage ? <Button variant="outline" disabled={disabled} onClick={() => void portal()}>Administrar método de pago</Button> : undefined} />
    {!canManage && <p className="text-sm text-muted-foreground">Tienes acceso de consulta. Un administrador con permiso de facturación puede realizar cambios.</p>}
    {search.checkout && <Alert><AlertDescription>{search.checkout === 'success' ? 'Regresaste del pago. Estamos verificando Stripe antes de actualizar tu contrato.' : 'Pago cancelado. No se realizó ningún cargo.'}</AlertDescription></Alert>}
    {search.change && <Alert><AlertDescription>Consulta abajo el estado y la fecha del cambio solicitado.</AlertDescription></Alert>}
    {portalError && <Alert variant="destructive"><AlertDescription>{portalError}</AlertDescription></Alert>}
    <SectionNav label="Facturación"><SectionNavButton active={view === 'details'} onClick={() => void navigate({ to: '/$tenantSlug/billing', params: { tenantSlug: activeTenant?.slug ?? '' }, search: { view: 'details', checkout: undefined, change: undefined } })}>Mi suscripción</SectionNavButton><SectionNavButton active={view === 'plans'} onClick={showPlans}>Planes</SectionNavButton></SectionNav>
    {(hasPending || hasManualReconciliation || query.isError) && <div className="flex flex-wrap items-center justify-between gap-2 text-sm" role="status"><p className="text-muted-foreground">{query.isError ? 'No pudimos actualizar el estado. Se muestra la última información disponible.' : hasManualReconciliation ? 'El estado necesita reconciliación antes de continuar.' : paused ? 'La actualización automática está pausada. Puedes consultar el estado de nuevo.' : 'Esperando confirmación del servidor. Actualizaremos el estado durante dos minutos.'}</p><Button variant="outline" disabled={query.isFetching || pending !== null} onClick={() => void reconcile()}>{query.isFetching || pending === 'reconcile' ? 'Actualizando…' : 'Actualizar estado'}</Button></div>}
    {paymentUrl && <Alert><AlertDescription>Hay un comprobante disponible para el complemento.<Button asChild variant="link"><a href={paymentUrl} target="_blank" rel="noopener noreferrer">Revisar pago</a></Button></AlertDescription></Alert>}
    {view === 'plans' ? offersQuery.isError ? <Alert variant="destructive"><AlertDescription>No pudimos consultar las ofertas.<Button variant="link" onClick={() => void offersQuery.refetch()}>Reintentar</Button></AlertDescription></Alert> : offersQuery.isPending ? <Skeleton className="h-64" /> : <PlansPage offers={offersQuery.data} summary={summary} disabled={disabled || summary.pending_addon_operations.length > 0 || summary.pending_changes.length > 0 || hasBlockingPendingContract || Boolean(summary.current_subscription?.cancel_at_period_end) || !['active', 'trial'].includes(summary.contract?.status ?? '')} onSelect={selectPlan} /> : <div className="space-y-6">

      <BillingDetailsPage commercial={<CommercialSummary checkoutError={checkoutError} summary={summary} disabled={disabled} onAddon={selectAddon} onResume={p => void resume(p)} onCancelPendingCheckout={() => void cancelCheckoutAttempt()} onCancelChange={id => choose({ kind: 'cancel-change', id, title: 'Cancelar cambio programado', description: 'Conservarás tu contrato y cantidades actuales. Esperaremos la confirmación del servidor.', branchLimit: null, userLimit: null } as Intent)} />} summary={summary} pending={pending} onViewPlans={showPlans} onOpenPortal={() => void portal()} onCancelPendingChange={id => choose({ kind: 'cancel-change', id, title: 'Cancelar cambio programado', description: 'Conservarás tu contrato y cantidades actuales. Después podrás retirar la cancelación del plan.', branchLimit: null, userLimit: null } as Intent)} onReactivate={() => choose({ kind: 'reactivate', title: 'Conservar el plan', description: 'Se retirará la cancelación programada y continuará la renovación.', branchLimit: null, userLimit: null })} onCancel={() => { const free = offersQuery.data?.find(p => p.code === 'free'); choose({ kind: 'cancel', title: 'Cancelar al finalizar el contrato', description: `Mantendrás acceso hasta el ${date(summary.current_subscription?.current_period_ends_at)}. Después se asignará el plan Free disponible entonces. Los datos se conservan y se liquidan los extras pendientes.`, branchLimit: free?.included_branches ?? null, userLimit: free?.max_users ?? null }) }} />
    </div>}
    <Dialog open={intent !== null} onOpenChange={open => { if (!open && !pending) setIntent(null) }}><DialogContent><DialogHeader><DialogTitle>{intent?.title}</DialogTitle><DialogDescription>{intent?.description}</DialogDescription></DialogHeader>
      {needsBranches && <fieldset className="space-y-3"><legend className="mb-2 text-sm font-medium">{intent?.branchLimit === 1 ? 'Selecciona la sucursal que seguirá operativa' : `Selecciona las ${intent?.branchLimit} sucursales que seguirán operativas`}</legend><p className="text-sm text-muted-foreground">Las demás conservarán sus datos, pero no podrán registrar nuevas operaciones cuando se aplique la reducción.</p>{summary.branches.map(b => <label className="flex items-center gap-2 text-sm" key={b.id}><input type="checkbox" checked={retained.includes(b.id)} disabled={pending !== null} onChange={e => setRetained(ids => e.target.checked ? [...ids, b.id] : ids.filter(id => id !== b.id))} />{b.name}</label>)}</fieldset>}
      {needsUsers && <fieldset className="space-y-3"><legend className="mb-2 text-sm font-medium">Selecciona los {intent?.userLimit} usuarios que seguirán activos</legend><p className="text-sm text-muted-foreground">Los demás se suspenderán cuando el cambio se haga efectivo. Su historial se conservará.</p>{summary.active_memberships.map(member => <label className="flex items-center gap-2 text-sm" key={member.id}><input type="checkbox" checked={retainedUsers.includes(member.id)} disabled={pending !== null || member.is_owner} onChange={event => setRetainedUsers(ids => event.target.checked ? [...ids, member.id] : ids.filter(id => id !== member.id))} />{member.name}{member.username ? ` (@${member.username})` : ''}{member.is_owner ? ' · Dueño' : ''}</label>)}</fieldset>}
      {error && <Alert variant="destructive"><AlertDescription>{error}</AlertDescription></Alert>}
      <DialogFooter><Button variant="outline" disabled={pending !== null} onClick={() => setIntent(null)}>Volver</Button><Button disabled={disabled || (needsBranches && retained.length !== intent?.branchLimit) || (needsUsers && retainedUsers.length !== intent?.userLimit)} onClick={() => void confirm()}>{pending ? 'Procesando…' : 'Confirmar solicitud'}</Button></DialogFooter>
    </DialogContent></Dialog>
  </>
}
function errorMessage(error: unknown): string {
  if (!axios.isAxiosError(error)) return 'No pudimos procesar la solicitud.'
  if (!error.response) return 'No pudimos conectar con el servidor. Revisa tu conexión e intenta de nuevo. No se pudo confirmar la operación.'
  const { status, data, headers } = error.response
  if (status === 429) {
    const retryAfterValue = Number(data?.retry_after ?? headers['retry-after'])
    const retryAfter = Number.isFinite(retryAfterValue) && retryAfterValue > 0
      ? Math.ceil(retryAfterValue)
      : null
    const requestId = headers['x-request-id'] ?? data?.request_id
    const reference = typeof requestId === 'string' && /^[a-zA-Z0-9-]{1,100}$/.test(requestId)
      ? ` · Referencia: ${requestId}`
      : ''

    return `Has realizado varias solicitudes. ${retryAfter ? `Intenta nuevamente en ${retryAfter} segundos.` : 'Espera unos segundos antes de intentar nuevamente.'}${reference}`
  }
  const message = status >= 500
    ? 'El servidor no pudo completar la operación. Actualiza el estado antes de reintentar.'
    : typeof data?.message === 'string'
      ? data.message
      : 'No pudimos confirmar la operación. Actualiza el estado antes de reintentar.'
  const requestId = headers['x-request-id']
  return `${message} (HTTP ${status})${typeof requestId === 'string' && /^[a-zA-Z0-9-]{1,100}$/.test(requestId) ? ` · Referencia: ${requestId}` : ''}`
}
