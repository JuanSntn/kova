import {
  ArrowRightIcon,
  CheckIcon,
  DownloadIcon,
  ExternalLinkIcon,
  FileTextIcon,
  Loader2Icon,
  MailIcon,
  MapPinIcon,
  PhoneIcon,
  UserIcon,
} from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { CardBrandIcon } from '@/components/ui/card-brand-icon'
import { Skeleton } from '@/components/ui/skeleton'
import {
  getBillingInvoices,
  type BillingSummary,
  type Invoice,
  type PlanCode,
  type SubscriptionStatus,
} from '@/features/billing/api'

type PendingAction =
  | PlanCode
  | 'portal'
  | 'cancel'
  | 'reactivate'
  | null

type BillingDetailsPageProps = {
  onCancel: () => void
  onOpenPortal: () => void
  onReactivate: () => void
  onViewPlans: () => void
  pending: PendingAction
  summary: BillingSummary | null
}

export function BillingDetailsPage({
  onCancel,
  onOpenPortal,
  onReactivate,
  onViewPlans,
  pending,
  summary,
}: BillingDetailsPageProps) {
  /*
   * Las facturas ahora viven fuera del summary.
   *
   * GET /api/billing/invoices?limit=5
   */
  const [invoices, setInvoices] = useState<Invoice[]>([])

  /*
   * Cursor que devuelve nuestro backend.
   *
   * Se utiliza para:
   *
   * GET /api/billing/invoices
   *   ?limit=5
   *   &cursor=in_xxx
   */
  const [invoiceCursor, setInvoiceCursor] =
    useState<string | null>(null)

  const [hasMoreInvoices, setHasMoreInvoices] =
    useState(false)

  /*
   * Loading inicial de la tabla.
   */
  const [invoicesLoading, setInvoicesLoading] =
    useState(true)

  /*
   * Loading exclusivo del botón "Cargar más".
   */
  const [
    invoicesLoadingMore,
    setInvoicesLoadingMore,
  ] = useState(false)

  const [invoicesError, setInvoicesError] =
    useState<string | null>(null)

  /**
   * Carga facturas desde el nuevo endpoint.
   *
   * append = false
   *   reemplaza las facturas actuales.
   *
   * append = true
   *   agrega la siguiente página.
   */
  const loadInvoices = useCallback(
    async (
      cursor: string | null = null,
      append = false,
    ) => {
      if (append) {
        setInvoicesLoadingMore(true)
      } else {
        setInvoicesLoading(true)
      }

      setInvoicesError(null)

      try {
        const result = await getBillingInvoices({
          limit: 5,
          cursor,
        })

        setInvoices((currentInvoices) => {
          /*
           * Primera página.
           */
          if (!append) {
            return result.data
          }

          /*
           * En "Cargar más" evitamos duplicados
           * por ID por seguridad.
           */
          const currentIds = new Set(
            currentInvoices.map(
              (invoice) => invoice.id,
            ),
          )

          const newInvoices = result.data.filter(
            (invoice) =>
              !currentIds.has(invoice.id),
          )

          return [
            ...currentInvoices,
            ...newInvoices,
          ]
        })

        setInvoiceCursor(
          result.pagination.next_cursor,
        )

        setHasMoreInvoices(
          result.pagination.has_more,
        )
      } catch {
        setInvoicesError(
          'No pudimos cargar las facturas.',
        )
      } finally {
        if (append) {
          setInvoicesLoadingMore(false)
        } else {
          setInvoicesLoading(false)
        }
      }
    },
    [],
  )

  /**
   * Una vez que tenemos el summary sabemos
   * si realmente existe un customer de Stripe.
   *
   * Si existe, cargamos las primeras 5 facturas.
   */
  useEffect(() => {
    if (!summary) {
      return
    }

    /*
     * Un tenant sin customer de Stripe
     * no tiene facturas.
     */
    if (!summary.stripe.customer_exists) {
      setInvoices([])
      setInvoiceCursor(null)
      setHasMoreInvoices(false)
      setInvoicesError(null)
      setInvoicesLoading(false)
      setInvoicesLoadingMore(false)

      return
    }

    /*
     * Reiniciamos la tabla antes de consultar
     * la primera página.
     */
    setInvoices([])
    setInvoiceCursor(null)
    setHasMoreInvoices(false)

    void loadInvoices()
  }, [summary, loadInvoices])

  /**
   * Cargar siguiente página.
   */
  const handleLoadMoreInvoices = () => {
    if (
      !invoiceCursor ||
      invoicesLoadingMore
    ) {
      return
    }

    void loadInvoices(
      invoiceCursor,
      true,
    )
  }

  /**
   * Reintento.
   *
   * Si ya tenemos facturas, significa que probablemente
   * falló un "Cargar más".
   *
   * Si no tenemos facturas, repetimos la carga inicial.
   */
  const handleRetryInvoices = () => {
    if (
      invoices.length > 0 &&
      invoiceCursor
    ) {
      void loadInvoices(
        invoiceCursor,
        true,
      )

      return
    }

    void loadInvoices()
  }

  /*
   * IMPORTANTE:
   * todos los hooks están antes de este return.
   */
  if (!summary) {
    return <BillingDetailsSkeleton />
  }

  const subscription =
    summary.current_subscription

  const isActive =
    subscription?.status === 'active' &&
    subscription.cancel_at_period_end === false

  const isCancelling =
    subscription?.status === 'active' &&
    subscription.cancel_at_period_end === true

  const isCancelled =
    subscription?.status === 'cancelled'

  const isOnPaidPlan =
    summary.current_plan !== 'free'

  return (
    <div className="space-y-6">
      {/* Resumen del plan */}
      <section aria-labelledby="subscription-title">
        <Card className="gap-0 overflow-hidden py-0 shadow-none">
          <div className="p-5 sm:p-6">
            <div className="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <h2
                    id="subscription-title"
                    className="text-base font-semibold"
                  >
                    Tu suscripción
                  </h2>

                  {subscription && (
                    <StatusBadge
                      status={subscription.status}
                    />
                  )}
                </div>

                <p className="mt-1 text-xs text-muted-foreground">
                  Administra tu plan y la renovación
                  de tu cuenta.
                </p>
              </div>

              <Button
                size="sm"
                variant="outline"
                className="shrink-0 self-start"
                onClick={onViewPlans}
              >
                Ver planes
                <ArrowRightIcon aria-hidden />
              </Button>
            </div>

            {subscription ? (
              <div className="mt-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                  <div>
                    <p className="text-xs font-medium text-muted-foreground">
                      Plan actual
                    </p>

                    <p className="mt-1 text-2xl font-semibold tracking-tight">
                      {subscription.plan_name}
                    </p>
                  </div>

                  <p className="sm:text-right">
                    <span className="text-2xl font-semibold tracking-tight">
                      {formatMoney(
                        subscription.plan_price,
                      )}
                    </span>

                    <span className="ml-1 text-xs text-muted-foreground">
                      / mes + IVA
                    </span>
                  </p>
                </div>

                <div className="mt-5 border-t pt-4">
                  {isCancelling ? (
                    <div className="flex flex-col gap-4 rounded-lg bg-muted/50 p-4 sm:flex-row sm:items-center sm:justify-between">
                      <div className="min-w-0">
                        <p className="text-sm font-semibold">
                          Cancelación programada
                        </p>

                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                          Tu suscripción al plan{' '}
                          <span className="font-medium text-foreground">
                            {
                              subscription.plan_name
                            }
                          </span>{' '}
                          se cancelará al finalizar el
                          periodo. Mantendrás acceso
                          hasta el{' '}
                          <span className="font-medium text-foreground">
                            {formatDate(
                              subscription.current_period_ends_at,
                            )}
                          </span>
                          . A partir de esa fecha
                          volverás al plan Free.
                        </p>
                      </div>

                      <Button
                        size="sm"
                        className="shrink-0 self-start sm:self-auto"
                        disabled={pending !== null}
                        onClick={onReactivate}
                      >
                        {pending === 'reactivate'
                          ? 'Procesando...'
                          : 'Conservar plan actual'}
                      </Button>
                    </div>
                  ) : isCancelled ? (
                    <div className="flex flex-col gap-4 rounded-lg bg-muted/50 p-4">
                      <div className="min-w-0">
                        <p className="text-sm font-semibold">
                          Suscripción finalizada
                        </p>

                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                          {subscription.ended_at ? (
                            <>
                              Tu suscripción al plan{' '}
                              <span className="font-medium text-foreground">
                                {
                                  subscription.plan_name
                                }
                              </span>{' '}
                              terminó el{' '}
                              <span className="font-medium text-foreground">
                                {formatDate(
                                  subscription.ended_at,
                                )}
                              </span>
                              . Actualmente estás en el
                              plan{' '}
                              <span className="font-medium text-foreground">
                                {
                                  summary.current_plan_name
                                }
                              </span>
                              .
                            </>
                          ) : (
                            <>
                              Tu suscripción al plan{' '}
                              <span className="font-medium text-foreground">
                                {
                                  subscription.plan_name
                                }
                              </span>{' '}
                              ya terminó. Actualmente
                              estás en el plan{' '}
                              <span className="font-medium text-foreground">
                                {
                                  summary.current_plan_name
                                }
                              </span>
                              .
                            </>
                          )}
                        </p>
                      </div>
                    </div>
                  ) : isActive ? (
                    <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                      <p className="text-xs text-muted-foreground">
                        Próximo cobro
                      </p>

                      <p className="text-sm font-medium">
                        {formatDate(
                          subscription.current_period_ends_at,
                        )}
                      </p>
                    </div>
                  ) : (
                    <p className="text-xs text-muted-foreground">
                      Este plan no tiene una
                      renovación programada.
                    </p>
                  )}
                </div>
              </div>
            ) : (
              <div className="mt-6 flex flex-col gap-4 rounded-lg bg-muted/50 p-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p className="text-sm font-semibold">
                    Plan gratuito
                  </p>

                  <p className="mt-1 text-xs text-muted-foreground">
                    No tienes una suscripción de pago
                    activa.
                  </p>
                </div>

                <Button
                  size="sm"
                  className="self-start sm:self-auto"
                  onClick={onViewPlans}
                >
                  Elegir plan
                </Button>
              </div>
            )}
          </div>
        </Card>
      </section>

      {/* Pago y facturación */}
      <section aria-labelledby="payment-billing-title">
        <Card className="gap-0 overflow-hidden py-0 shadow-none">
          <header className="flex items-start justify-between gap-4 border-b px-5 py-4">
            <div>
              <h2
                id="payment-billing-title"
                className="text-sm font-semibold"
              >
                Pago y facturación
              </h2>

              <p className="mt-1 text-xs text-muted-foreground">
                Método de pago e información fiscal.
              </p>
            </div>

            {summary.stripe.customer_exists && (
              <Button
                size="xs"
                variant="ghost"
                className="shrink-0"
                disabled={pending !== null}
                onClick={onOpenPortal}
              >
                Administrar
                <ExternalLinkIcon aria-hidden />
              </Button>
            )}
          </header>

          <div className="grid divide-y lg:grid-cols-[0.85fr_1.15fr] lg:divide-x lg:divide-y-0">
            {/* Método de pago */}
            <div className="p-5">
              <p className="text-xs font-medium text-muted-foreground">
                Método de pago
              </p>

              {summary.payment_method ? (
                <div className="mt-3 flex items-center gap-3">
                  <div className="flex size-10 shrink-0 items-center justify-center rounded-lg border bg-muted/30">
                    <CardBrandIcon
                      brand={
                        summary.payment_method
                          .brand
                      }
                      className="size-8"
                    />
                  </div>

                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">
                      {capitalize(
                        summary.payment_method
                          .brand ?? 'Tarjeta',
                      )}{' '}
                      ••••{' '}
                      {summary.payment_method
                        .last4 ?? '0000'}
                    </p>

                    <p className="mt-1 text-xs text-muted-foreground">
                      Vence{' '}
                      {summary.payment_method
                        .exp_month ?? '00'}
                      /
                      {summary.payment_method
                        .exp_year ?? '0000'}
                    </p>
                  </div>

                  <Button
                    size="sm"
                    variant="outline"
                    className="shrink-0"
                    disabled={pending !== null}
                    onClick={onOpenPortal}
                  >
                    Cambiar
                  </Button>
                </div>
              ) : (
                <div className="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                  <p className="text-sm text-muted-foreground">
                    Sin método registrado.
                  </p>

                  {summary.stripe
                    .customer_exists && (
                      <Button
                        size="sm"
                        variant="outline"
                        className="self-start sm:self-auto"
                        disabled={pending !== null}
                        onClick={onOpenPortal}
                      >
                        Agregar
                      </Button>
                    )}
                </div>
              )}
            </div>

            {/* Datos fiscales */}
            <div className="p-5">
              <p className="text-xs font-medium text-muted-foreground">
                Datos de facturación
              </p>

              <div className="mt-4 grid gap-x-6 gap-y-4 sm:grid-cols-2">
                <BillingDetail
                  icon={UserIcon}
                  label="Razón social"
                  value={
                    summary.billing_details?.name
                  }
                />

                <BillingDetail
                  icon={MailIcon}
                  label="Correo"
                  value={
                    summary.billing_details?.email
                  }
                />

                <BillingDetail
                  icon={PhoneIcon}
                  label="Teléfono"
                  value={
                    summary.billing_details?.phone
                  }
                />

                <BillingDetail
                  icon={MapPinIcon}
                  label="Dirección"
                  value={formatAddress(summary)}
                />
              </div>
            </div>
          </div>
        </Card>
      </section>

      {/* Facturas */}
      <InvoicesSection
        invoices={invoices}
        loading={invoicesLoading}
        loadingMore={invoicesLoadingMore}
        hasMore={hasMoreInvoices}
        error={invoicesError}
        onLoadMore={handleLoadMoreInvoices}
        onRetry={handleRetryInvoices}
        onOpenPortal={onOpenPortal}
        portalEnabled={
          summary.stripe.customer_exists
        }
      />

      {/* Acción de cancelación */}
      {isOnPaidPlan && !isCancelling && (
        <section
          aria-labelledby="cancel-subscription-title"
          className="border-t pt-5"
        >
          <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0">
              <h2
                id="cancel-subscription-title"
                className="text-sm font-semibold"
              >
                Cancelar suscripción
              </h2>

              <p className="mt-1 text-xs leading-5 text-muted-foreground">
                Seguirás teniendo acceso al plan
                hasta que termine el periodo pagado.
                Después pasarás al plan gratuito.
              </p>
            </div>

            <Button
              size="sm"
              variant="outline"
              className="shrink-0 self-start text-destructive hover:text-destructive sm:self-auto"
              disabled={pending !== null}
              onClick={onCancel}
            >
              {pending === 'cancel'
                ? 'Cancelando...'
                : 'Cancelar suscripción'}
            </Button>
          </div>
        </section>
      )}
    </div>
  )
}

function InvoicesSection({
  invoices,
  loading,
  loadingMore,
  hasMore,
  error,
  onLoadMore,
  onRetry,
  onOpenPortal,
  portalEnabled,
}: {
  invoices: Invoice[]
  loading: boolean
  loadingMore: boolean
  hasMore: boolean
  error: string | null
  onLoadMore: () => void
  onRetry: () => void
  onOpenPortal: () => void
  portalEnabled: boolean
}) {
  const countLabel = loading
    ? 'Cargando comprobantes...'
    : getInvoiceCountLabel(invoices.length)

  return (
    <section aria-labelledby="invoices-title">
      <div className="mb-3 flex items-center justify-between gap-3">
        <div className="min-w-0">
          <h2
            id="invoices-title"
            className="text-sm font-semibold"
          >
            Facturas
          </h2>

          <p className="mt-1 text-xs text-muted-foreground">
            {countLabel}
          </p>
        </div>

        {portalEnabled &&
          !loading &&
          invoices.length > 0 && (
            <Button
              size="xs"
              variant="ghost"
              onClick={onOpenPortal}
            >
              Ver todas
              <ExternalLinkIcon aria-hidden />
            </Button>
          )}
      </div>

      <Card className="gap-0 overflow-hidden py-0 shadow-none">
        {loading ? (
          <InvoicesSkeleton />
        ) : invoices.length > 0 ? (
          <>
            {/* Encabezado de tabla */}
            <div className="hidden grid-cols-[minmax(0,1fr)_8.5rem_7.5rem_7rem_2rem] gap-3 border-b bg-muted/30 px-4 py-2 text-[11px] font-medium text-muted-foreground md:grid">
              <span>Factura</span>
              <span>Fecha</span>
              <span>Estado</span>

              <span className="text-right">
                Total
              </span>

              <span className="sr-only">
                Descargar
              </span>
            </div>

            {/* Facturas cargadas */}
            <div>
              {invoices.map((invoice) => (
                <InvoiceRow
                  key={invoice.id}
                  invoice={invoice}
                />
              ))}

              {/* Skeleton mientras carga otra página */}
              {loadingMore && (
                <InvoiceLoadingRow />
              )}
            </div>

            {/* Footer de paginación */}
            {(hasMore || error) && (
              <div className="flex flex-col items-center justify-center gap-2 border-t px-4 py-4">
                {error && (
                  <p className="text-center text-xs text-destructive">
                    {error}
                  </p>
                )}

                {hasMore ? (
                  <Button
                    size="sm"
                    variant="outline"
                    disabled={loadingMore}
                    onClick={
                      error
                        ? onRetry
                        : onLoadMore
                    }
                  >
                    {loadingMore ? (
                      <>
                        <Loader2Icon
                          className="animate-spin"
                          aria-hidden
                        />

                        Cargando...
                      </>
                    ) : error ? (
                      'Reintentar'
                    ) : (
                      'Cargar más facturas'
                    )}
                  </Button>
                ) : error ? (
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={onRetry}
                  >
                    Reintentar
                  </Button>
                ) : null}
              </div>
            )}
          </>
        ) : error ? (
          /*
           * Error en la primera carga.
           */
          <div className="flex min-h-40 flex-col items-center justify-center px-4 py-6 text-center">
            <div className="flex size-8 items-center justify-center rounded-lg bg-muted">
              <FileTextIcon
                className="size-3.5 text-muted-foreground"
                aria-hidden
              />
            </div>

            <p className="mt-2 text-sm font-medium">
              No pudimos cargar las facturas
            </p>

            <p className="mt-1 max-w-sm text-xs text-muted-foreground">
              Intenta nuevamente para consultar tus
              comprobantes.
            </p>

            <Button
              size="sm"
              variant="outline"
              className="mt-4"
              onClick={onRetry}
            >
              Reintentar
            </Button>
          </div>
        ) : (
          /*
           * Customer existente, pero sin facturas.
           */
          <div className="flex min-h-32 flex-col items-center justify-center px-4 py-6 text-center">
            <div className="flex size-8 items-center justify-center rounded-lg bg-muted">
              <FileTextIcon
                className="size-3.5 text-muted-foreground"
                aria-hidden
              />
            </div>

            <p className="mt-2 text-sm font-medium">
              Aún no hay facturas
            </p>

            <p className="mt-1 max-w-sm text-xs text-muted-foreground">
              Los comprobantes aparecerán aquí
              después de tu primer cobro.
            </p>
          </div>
        )}
      </Card>
    </section>
  )
}

function InvoiceRow({
  invoice,
}: {
  invoice: Invoice
}) {
  const downloadUrl =
    invoice.pdf ?? invoice.url

  /*
   * El backend ya genera una etiqueta apropiada.
   *
   * Ejemplos:
   *
   * paid  -> Pagada
   * draft -> Borrador
   *
   * o casos especiales:
   *
   * draft + total negativo -> Crédito pendiente
   * paid + total $0        -> Sin cargo
   */
  const statusLabel =
    invoice.status_label ||
    {
      paid: 'Pagada',
      open: 'Pendiente',
      draft: 'Borrador',
      void: 'Anulada',
      uncollectible: 'Incobrable',
    }[invoice.status] ||
    invoice.status

  const isPaid =
    invoice.status === 'paid'

  return (
    <div className="grid gap-2 border-b px-4 py-2.5 last:border-b-0 md:grid-cols-[minmax(0,1fr)_8.5rem_7.5rem_7rem_2rem] md:items-center md:gap-3">
      {/* Factura */}
      <div className="min-w-0">
        <p className="truncate text-xs font-medium">
          {invoice.number ??
            'Factura de suscripción'}
        </p>

        <p className="mt-0.5 truncate text-[11px] text-muted-foreground">
          {invoice.plan_name || '—'}
        </p>
      </div>

      {/* Fecha */}
      <p className="text-xs text-muted-foreground">
        <span className="mr-2 md:hidden">
          Fecha:
        </span>

        {formatDate(invoice.date)}
      </p>

      {/* Estado */}
      <div>
        <Badge
          variant={
            isPaid ? 'success' : 'outline'
          }
        >
          {isPaid && (
            <CheckIcon aria-hidden />
          )}

          {statusLabel}
        </Badge>
      </div>

      {/* Total */}
      <p className="text-xs font-medium md:text-right">
        {formatMoney(invoice.total)}
      </p>

      {/* PDF / URL */}
      <div className="md:flex md:justify-end">
        {downloadUrl ? (
          <Button
            asChild
            aria-label={`Descargar ${invoice.number ?? 'factura'
              }`}
            size="icon-sm"
            variant="ghost"
          >
            <a
              href={downloadUrl}
              rel="noreferrer"
              target="_blank"
            >
              <DownloadIcon aria-hidden />
            </a>
          </Button>
        ) : (
          <span className="text-xs text-muted-foreground">
            No disponible
          </span>
        )}
      </div>
    </div>
  )
}

/**
 * Skeleton inicial de la tabla.
 *
 * Simula las primeras 5 facturas.
 */
function InvoicesSkeleton() {
  return (
    <div
      aria-label="Cargando facturas"
      aria-busy="true"
    >
      {/* Header */}
      <div className="hidden grid-cols-[minmax(0,1fr)_8.5rem_7.5rem_7rem_2rem] gap-3 border-b bg-muted/30 px-4 py-2 md:grid">
        <Skeleton className="h-3 w-16" />
        <Skeleton className="h-3 w-12" />
        <Skeleton className="h-3 w-12" />
        <Skeleton className="ml-auto h-3 w-12" />
        <Skeleton className="size-3" />
      </div>

      {Array.from({
        length: 5,
      }).map((_, index) => (
        <div
          key={index}
          className="grid gap-2 border-b px-4 py-3 last:border-b-0 md:grid-cols-[minmax(0,1fr)_8.5rem_7.5rem_7rem_2rem] md:items-center md:gap-3"
        >
          <div className="space-y-2">
            <Skeleton className="h-3 w-32" />
            <Skeleton className="h-2.5 w-20" />
          </div>

          <Skeleton className="h-3 w-20" />

          <Skeleton className="h-5 w-20 rounded-full" />

          <Skeleton className="h-3 w-16 md:ml-auto" />

          <Skeleton className="size-7 rounded-md" />
        </div>
      ))}
    </div>
  )
}

/**
 * Skeleton pequeño para cuando presionan
 * "Cargar más facturas".
 */
function InvoiceLoadingRow() {
  return (
    <div
      className="grid gap-2 border-b px-4 py-3 md:grid-cols-[minmax(0,1fr)_8.5rem_7.5rem_7rem_2rem] md:items-center md:gap-3"
      aria-label="Cargando más facturas"
      aria-busy="true"
    >
      <div className="space-y-2">
        <Skeleton className="h-3 w-32" />
        <Skeleton className="h-2.5 w-20" />
      </div>

      <Skeleton className="h-3 w-20" />

      <Skeleton className="h-5 w-20 rounded-full" />

      <Skeleton className="h-3 w-16 md:ml-auto" />

      <Skeleton className="size-7 rounded-md" />
    </div>
  )
}

function BillingDetail({
  icon: Icon,
  label,
  value,
}: {
  icon: typeof UserIcon
  label: string
  value?: string | null
}) {
  return (
    <div className="flex min-w-0 gap-2">
      <Icon
        className="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
        aria-hidden
      />

      <div className="min-w-0">
        <p className="text-[11px] font-medium text-muted-foreground">
          {label}
        </p>

        <p className="mt-0.5 break-words text-xs font-medium">
          {value || 'Sin registrar'}
        </p>
      </div>
    </div>
  )
}

function StatusBadge({
  status,
}: {
  status: SubscriptionStatus
}) {
  const config: Record<
    SubscriptionStatus,
    {
      label: string
      variant:
      | 'outline'
      | 'secondary'
      | 'success'
    }
  > = {
    active: {
      label: 'Activa',
      variant: 'success',
    },

    trial: {
      label: 'Prueba',
      variant: 'secondary',
    },

    past_due: {
      label: 'Pago pendiente',
      variant: 'outline',
    },

    cancelled: {
      label: 'Cancelada',
      variant: 'outline',
    },
  }

  const { label, variant } =
    config[status]

  return (
    <Badge variant={variant}>
      {label}
    </Badge>
  )
}

function BillingDetailsSkeleton() {
  return (
    <div
      className="space-y-6"
      aria-label="Cargando información de facturación"
    >
      <Skeleton className="h-64 rounded-xl" />

      <Skeleton className="h-60 rounded-xl" />

      <Skeleton className="h-52 rounded-xl" />
    </div>
  )
}

function formatAddress(
  summary: BillingSummary,
) {
  const address =
    summary.billing_details?.address

  if (!address) {
    return null
  }

  return [
    address.line1,
    address.line2,
    address.city,
    address.state,
    address.postal_code,
    address.country,
  ]
    .filter(Boolean)
    .join(', ')
}

function formatDate(
  value?: string | null,
) {
  if (!value) {
    return 'Fecha no disponible'
  }

  return new Date(
    value,
  ).toLocaleDateString('es-MX', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

function formatMoney(
  value: string | number,
) {
  const amount = Number(value)

  if (!Number.isFinite(amount)) {
    return '$0'
  }

  return new Intl.NumberFormat(
    'es-MX',
    {
      style: 'currency',
      currency: 'MXN',

      maximumFractionDigits:
        amount % 1 === 0
          ? 0
          : 2,
    },
  ).format(amount)
}

function capitalize(value: string) {
  if (!value) {
    return ''
  }

  return (
    value.charAt(0).toUpperCase() +
    value.slice(1)
  )
}

function getInvoiceCountLabel(
  count: number,
) {
  if (count === 0) {
    return 'Sin comprobantes'
  }

  if (count === 1) {
    return '1 comprobante'
  }

  return `${count} comprobantes cargados`
}