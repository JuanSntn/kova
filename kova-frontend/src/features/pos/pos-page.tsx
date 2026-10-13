import { Link } from '@tanstack/react-router'
import { useCashContext } from '@/features/cash/api'
import { cashContextLocked } from '@/features/cash/storage'
import { useEffect, useState } from 'react'
import { ProductOptions } from './components/product-options'
import { cents } from './money'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { useSession } from '@/features/auth/session-context'
import { useBranch } from '@/features/branches/branch-context'
import { CheckoutSheet } from './components/checkout-sheet'
import { PosCart } from './components/pos-cart'
import { PosCategoryFilter } from './components/pos-category-filter'
import { PosProductGrid } from './components/pos-product-grid'
import { PosSearch } from './components/pos-search'
import { usePosCart } from './hooks/use-pos-cart'
import { usePosCheckout } from './hooks/use-pos-checkout'
import { usePosFilters } from './hooks/use-pos-filters'
import { usePosProducts } from './hooks/use-pos-products'
import type { CheckoutContext, CheckoutReceipt, PosCartItem, PosCartTotals, PosCategory, PosProduct } from './types'

export function PosPage() {
  const { activeTenant } = useSession()
  const { activeBranchId, isLoading: loadingBranch } = useBranch()
  const catalog = usePosProducts(activeTenant?.id ?? '', activeBranchId ?? '')
  if (loadingBranch || (activeBranchId && catalog.isLoading)) return <PosPageSkeleton />
  if (!activeTenant?.membership_id || !activeBranchId) {
    return <Alert><AlertDescription>Selecciona un negocio y una sucursal para abrir el punto de venta.</AlertDescription></Alert>
  }
  const context = { tenant: activeTenant.id, branch: activeBranchId, membership: activeTenant.membership_id }
  return <PosWorkspace key={Object.values(context).join(':')} context={context}
    products={catalog.products} categories={catalog.categories} hasError={catalog.hasError}
    refetch={() => { void catalog.refetchAll() }} />
}

function PosWorkspace({ context, products, categories, hasError, refetch }: {
  context: CheckoutContext; products: PosProduct[]; categories: PosCategory[]; hasError: boolean; refetch: () => void
}) {
  const checkout = usePosCheckout(context)
  const [configuring, setConfiguring] = useState<PosProduct | null>(null)
  const cash = useCashContext(context)
  const { activeTenant } = useSession()
  const canCheckout = cash.data?.can_checkout === true && !cashContextLocked()
  // Stored amounts are never restored: resolve the current catalogue again.
  const restored = (checkout.initial?.order ? [] : checkout.initial?.items ?? []).map(item => {
    const product = products.find(product => product.id === item.product_id)
    const ids = item.modifiers ?? []
    const options = (product?.modifier_groups ?? []).flatMap(g => g.options.map(o => ({ ...o, group: g.name })))
    const modifiers = ids.map(id => { const o = options.find(o => o.id === id); return { modifier_option_id: id, group_name: o?.group ?? '', option_name: o?.name ?? 'Opción no disponible', price_adjustment: Number(o?.price ?? 0) } })
    return { ...cartLine(item.product_id, (product?.name ?? 'Producto no disponible') + (modifiers.length ? ' · ' + modifiers.map(m => m.option_name).join(', ') : ''), (cents(product?.price ?? 0) + modifiers.reduce((sum, m) => sum + cents(m.price_adjustment), 0)) / 100, item.quantity), line_id: [item.product_id, ...ids.slice().sort()].join(':'), modifiers }
  })
  const cart = usePosCart(restored, checkout.initial?.paymentMethod ?? 'cash')
  const filters = usePosFilters({ products, categories })
  const locked = ['preparing', 'sending', 'uncertain', 'complete'].includes(checkout.phase)
  const needsShift = !locked && !cash.isError && !cash.isPending && cash.data?.shift_required === true
    && !cash.data.current_shift && !cash.data.read_only && !cash.data.closure_required && !cashContextLocked()
  const saveCart = checkout.saveCart
  const unavailable = cart.items.some(item => !products.some(product => product.id === item.product_id && product.can_use))
  useEffect(() => {
    saveCart(cart.items, cart.paymentMethod)
  }, [cart.items, cart.paymentMethod, saveCart])

  const quoteItems = checkout.quote?.lines.map((line, index) => ({ ...cartLine(line.product_id, line.product_name_snapshot + (line.modifiers_snapshot?.length ? ' · ' + line.modifiers_snapshot.map(m => m.option_name).join(', ') : ''), Number(line.unit_price_snapshot), line.quantity), line_id: String(index) })) ?? []
  const quoteTotals = totals(Number(checkout.quote?.total ?? 0))
  const receipt: CheckoutReceipt | null = checkout.order ? {
    order: checkout.order,
    payment_method: checkout.order.payment_method,
    items: checkout.order.items.map((item, index) => ({ ...cartLine(item.product_id ?? String(index), item.product_name + (item.modifiers?.length ? ' · ' + item.modifiers.map(m => m.option_name).join(', ') : ''), Number(item.unit_price), item.quantity), line_id: String(index) })),
    totals: totals(Number(checkout.order.total)),
  } : null
  function newSale() {
    if (checkout.finish()) { cart.clearCart(); filters.clearFilters(); refetch() }
  }
  return <>
    <div className="w-full min-w-0 space-y-4">
      {!canCheckout && !locked && !needsShift && <Alert><AlertDescription>
        <p>{cash.isError ? 'No se pudo verificar el estado de caja.' : cash.isPending ? 'Consultando disponibilidad de caja…' : cash.data?.read_only ? 'La suscripción está en modo de consulta. No se pueden registrar ventas nuevas.' : cash.data?.closure_required ? 'Cierra los turnos pendientes antes de cobrar.' : cashContextLocked() ? 'Verifica la operación pendiente en Caja y turnos.' : 'Necesitas abrir un turno antes de cobrar.'}</p>
        {activeTenant && cash.data?.permissions.view && <Button asChild variant="outline" className="mt-2"><Link to="/$tenantSlug/cash" params={{ tenantSlug: activeTenant.slug }}>Abrir caja</Link></Button>}
        {cash.isError && <Button variant="outline" onClick={() => void cash.refetch()}>Reintentar</Button>}
      </AlertDescription></Alert>}
      <div role="status" aria-live="polite" className="text-sm empty:sr-only">
        {checkout.phase === 'preparing' && 'Preparando cobro…'}
        {checkout.phase === 'ready' && 'Listo para confirmar. Revisa los importes actualizados.'}
        {checkout.phase === 'sending' && 'Registrando o verificando venta…'}
        {checkout.phase === 'complete' && 'Venta registrada: ' + (checkout.order?.folio ?? '')}
      </div>
      {(checkout.message || checkout.storageError) && <Alert variant="destructive"><AlertDescription>{checkout.storageError || checkout.message}</AlertDescription></Alert>}
      {checkout.phase === 'uncertain' && <Alert><AlertDescription className="space-y-3">
        <p className="font-medium">Resultado pendiente de verificar</p>
        <p>Conservamos el intento. Verifica la venta antes de volver a cobrar o cambiar de sucursal.</p>
        <div className="flex flex-wrap gap-2">
          <Button onClick={() => void checkout.verify()}>Verificar venta</Button>
          <Button variant="outline" onClick={() => void checkout.retry()}>Reenviar el mismo intento</Button>
        </div>
      </AlertDescription></Alert>}
      {hasError && <Alert variant="destructive"><AlertDescription>No pudimos cargar el catálogo. <Button variant="outline" onClick={refetch}>Reintentar</Button></AlertDescription></Alert>}
      {unavailable && !locked && <Alert><AlertDescription>Hay productos que ya no están disponibles. Retíralos del carrito antes de cobrar.</AlertDescription></Alert>}
      <fieldset disabled={locked} className="min-w-0 border-0 p-0">
        <legend className="sr-only">Productos y carrito</legend>
        <div className="grid min-w-0 gap-4 xl:grid-cols-[minmax(0,1fr)_380px]">
          <section className="relative isolate min-w-0 overflow-hidden rounded-xl border bg-background">
            <div inert={needsShift} aria-hidden={needsShift || undefined} className={needsShift ? 'pointer-events-none select-none blur-sm' : undefined}>
            <div className="space-y-2 border-b p-3">
              {cash.data?.current_shift && <p className="break-words text-xs text-muted-foreground">Caja: {cash.data.current_shift.register.name} · Turno de {cash.data.current_shift.responsible}</p>}
              <PosSearch value={filters.search} onChange={filters.setSearch} />
              <PosCategoryFilter categories={filters.visibleCategories} categoryCounts={filters.categoryCounts}
                productsCount={products.length} selectedCategory={filters.selectedCategory} onSelectCategory={filters.setSelectedCategory} />
            </div>
            <div className="p-3">
                <PosProductGrid products={filters.filteredProducts} hasCatalogProducts={products.length > 0}
                  onAdd={product => { if (product.modifier_groups?.length) setConfiguring(product); else cart.addProduct(product) }} onClearFilters={filters.clearFilters} />
            </div>
            </div>
              {needsShift && <div className="absolute inset-0 flex items-center justify-center bg-background/50 p-4">
                <div role="status" className="flex max-w-sm flex-col items-center gap-4 rounded-xl border bg-card p-5 text-center shadow-sm">
                  <p className="text-base font-medium">Necesitas abrir un turno antes de cobrar.</p>
                  {activeTenant && cash.data?.permissions.view && <Button asChild><Link to="/$tenantSlug/cash" params={{ tenantSlug: activeTenant.slug }}>Abrir caja</Link></Button>}
                </div>
              </div>}
          </section>
          <aside className="min-w-0"><PosCart items={checkout.order ? [] : cart.items} itemCount={checkout.order ? 0 : cart.itemCount}
            totals={checkout.order ? totals(0) : cart.totals} paymentMethod={cart.paymentMethod} onPaymentMethodChange={cart.setPaymentMethod}
            onIncrease={cart.increaseQuantity} onDecrease={cart.decreaseQuantity} onRemove={cart.removeItem} onClear={cart.clearCart}
            checkoutDisabled={!canCheckout}
            onCheckout={() => { if (canCheckout && !unavailable && !hasError) void checkout.prepare(cart.items, cart.paymentMethod) }} /></aside>
        </div>
      </fieldset>
    </div>
    {configuring && !locked && <ProductOptions product={configuring} close={() => setConfiguring(null)} add={ids => { cart.addProduct(configuring, ids); setConfiguring(null) }} />}
    {(checkout.phase === 'ready' || (checkout.phase === 'sending' && checkout.quote) || receipt) && <CheckoutSheet
      open onOpenChange={open => { if (!open) { if (receipt) newSale(); else checkout.closePrepared() } }}
      items={receipt?.items ?? quoteItems} totals={receipt?.totals ?? quoteTotals}
      taxBase={checkout.order?.tax_base_total ?? checkout.quote?.tax_base_total}
      taxTotal={checkout.order?.tax_total ?? checkout.quote?.tax_total}
      paymentMethod={receipt?.payment_method ?? cart.paymentMethod} checkingOut={checkout.phase === 'sending'}
      receipt={receipt} onConfirm={({ amountReceived }) => void checkout.confirm(amountReceived)} onNewSale={newSale} />}
  </>
}
function cartLine(id: string, name: string, price: number, quantity: number): PosCartItem {
  return { line_id: id, product_id: id, name_snapshot: name, unit_price: price, quantity, category_name: '', image_path: null, modifiers: [], notes: null }
}
function totals(total: number): PosCartTotals {
  return { subtotal: total, total, discount_total: 0, service_charge_total: 0, tip_total: 0 }
}
function PosPageSkeleton() {
  return <div role="status" className="p-4 text-sm text-muted-foreground">Cargando punto de venta…</div>
}
