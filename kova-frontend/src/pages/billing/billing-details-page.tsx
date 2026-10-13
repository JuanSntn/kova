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
import { useInfiniteQuery } from '@tanstack/react-query'

import { useSession } from '@/features/auth/session-context'
import { money as contractMoney } from '@/features/billing/presentation'
import { StampUsageCard } from '@/features/billing/stamp-usage-card'
import { queryKeys } from '@/lib/query-keys'

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
  commercial?: React.ReactNode
  onCancel: () => void
  onCancelPendingChange: (id: string) => void
  onOpenPortal: () => void
  onReactivate: () => void
  onViewPlans: () => void
  pending: PendingAction
  summary: BillingSummary | null
}

export function BillingDetailsPage({
  commercial,
  onCancel,
  onCancelPendingChange,
  onOpenPortal,
  onReactivate,
  onViewPlans,
  pending,
  summary,
}: BillingDetailsPageProps) {
  const { activeTenant } = useSession()

  const invoiceQuery = useInfiniteQuery({
    queryKey: [
      ...queryKeys.billing(activeTenant?.id ?? ''),
      'invoices',
    ],
    queryFn: ({ pageParam }) =>
      getBillingInvoices({
        limit: 5,
        cursor: pageParam,
      }),
    initialPageParam: null as string | null,
    getNextPageParam: (last) =>
      last.pagination.has_more
        ? last.pagination.next_cursor ?? undefined
        : undefined,
    enabled: Boolean(
      activeTenant?.id
      && summary?.stripe.customer_exists,
    ),
  })

  const invoices = [
    ...new Map(
      (
        invoiceQuery.data?.pages.flatMap(
          (page) => page.data,
        )
        ?? []
      ).map((invoice) => [
        invoice.id,
        invoice,
      ]),
    ).values(),
  ]

  if (!summary) {
    return <BillingDetailsSkeleton />
  }

  const subscription =
    summary.current_subscription

  const isCancelling =
    subscription?.status === 'active'
    && subscription.cancel_at_period_end

  const isOnPaidPlan =
    (summary.contract?.amount ?? 0) > 0

  const cancelablePendingChange =
    summary.pending_changes.find(
      (change) =>
        change.status === 'pending'
        && change.change_mode === 'period_end',
    )

  const processingChange =
    summary.pending_changes.some(
      (change) =>
        change.status === 'processing'
        || change.change_mode === 'immediate',
    )

  const hasPendingChange =
    Boolean(cancelablePendingChange)
    || processingChange

  return (
    <div className="space-y-5">
      <Card className="gap-0 overflow-hidden py-0 shadow-none">
        <header className="flex items-start justify-between gap-4 border-b px-5 py-4">
          <div>
            <div className="flex flex-wrap items-center gap-2">
              <h2 className="text-sm font-semibold">
                Tu suscripción
              </h2>

              {subscription && (
                <StatusBadge
                  status={subscription.status}
                />
              )}
            </div>

            <p className="mt-1 text-xs text-muted-foreground">
              Plan actual, precio y próxima renovación.
            </p>
          </div>

          <Button
            size="sm"
            variant="outline"
            className="shrink-0"
            onClick={onViewPlans}
          >
            Ver planes
            <ArrowRightIcon aria-hidden />
          </Button>
        </header>

        <div className="p-5">
          {subscription ? (
            <>
              <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                  <p className="text-xs text-muted-foreground">
                    Plan actual
                  </p>

                  <p className="mt-1 text-xl font-semibold tracking-tight">
                    {subscription.plan_name}
                  </p>
                </div>

                <div className="sm:text-right">
                  <p className="text-xl font-semibold tracking-tight">
                    {contractMoney(
                      subscription.amount,
                      subscription.currency,
                    )}
                  </p>

                  <p className="mt-0.5 text-xs text-muted-foreground">
                    /{' '}
                    {subscription.interval === 'year'
                      ? 'año'
                      : 'mes'}{' '}
                    + IVA
                  </p>
                </div>
              </div>

              <div className="mt-5 grid gap-4 border-t pt-4 sm:grid-cols-3">
                <SummaryValue
                  label="Periodicidad"
                  value={
                    subscription.interval === 'year'
                      ? 'Anual'
                      : 'Mensual'
                  }
                />

                <SummaryValue
                  label={
                    isCancelling
                      ? 'Acceso hasta'
                      : 'Próximo cobro'
                  }
                  value={formatDate(
                    subscription.current_period_ends_at,
                  )}
                />

                <SummaryValue
                  label="Estado"
                  value={
                    isCancelling
                      ? 'Cancelación programada'
                      : subscription.status === 'active'
                        ? 'Renovación activa'
                        : statusText(subscription.status)
                  }
                />
              </div>

              {isCancelling && (
                <div className="mt-4 flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-center sm:justify-between">
                  <div className="max-w-xl space-y-1">
                    <p className="text-xs leading-5 text-muted-foreground">
                      Mantendrás el plan hasta el{' '}
                      <span className="font-medium text-foreground">
                        {formatDate(
                          subscription.current_period_ends_at,
                        )}
                      </span>
                      . Después pasarás al plan Free.
                    </p>

                    {cancelablePendingChange && (
                      <p className="text-xs leading-5 text-destructive">
                        Cancela primero el cambio programado en Actividad pendiente para conservar este plan.
                      </p>
                    )}

                    {processingChange && (
                      <p className="text-xs leading-5 text-destructive">
                        Hay un cambio de suscripción en proceso. Actualiza su estado antes de conservar este plan.
                      </p>
                    )}
                  </div>

                  {cancelablePendingChange ? (
                    <Button
                      size="sm"
                      variant="outline"
                      disabled={
                        pending !== null
                        || !summary.actions.can_manage
                      }
                      onClick={() =>
                        onCancelPendingChange(
                          cancelablePendingChange.id,
                        )
                      }
                    >
                      Cancelar cambio programado
                    </Button>
                  ) : (
                    <Button
                      size="sm"
                      disabled={
                        pending !== null
                        || !summary.actions.can_manage
                        || hasPendingChange
                      }
                      onClick={onReactivate}
                    >
                      {pending === 'reactivate'
                        ? 'Procesando...'
                        : 'Conservar plan'}
                    </Button>
                  )}
                </div>
              )}
            </>
          ) : (
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <p className="text-sm font-medium">
                  Plan gratuito
                </p>

                <p className="mt-1 text-xs text-muted-foreground">
                  No tienes una suscripción de pago activa.
                </p>
              </div>

              <Button
                size="sm"
                onClick={onViewPlans}
              >
                Elegir plan
              </Button>
            </div>
          )}
        </div>
      </Card>

      {commercial}

      <StampUsageCard usage={summary.stamp_usage} canManage={summary.actions.can_manage} />

      <Card className="gap-0 overflow-hidden py-0 shadow-none">
        <header className="flex items-start justify-between gap-4 border-b px-5 py-4">
          <div>
            <h2 className="text-sm font-semibold">
              Pago y facturación
            </h2>

            <p className="mt-1 text-xs text-muted-foreground">
              Método de pago e información de facturación.
            </p>
          </div>

          {summary.stripe.customer_exists && (
            <Button
              size="sm"
              variant="ghost"
              disabled={
                pending !== null
                || !summary.actions.can_manage
              }
              onClick={onOpenPortal}
            >
              Administrar
              <ExternalLinkIcon aria-hidden />
            </Button>
          )}
        </header>

        <div className="grid divide-y lg:grid-cols-2 lg:divide-x lg:divide-y-0">
          <div className="p-5">
            <p className="text-xs font-medium text-muted-foreground">
              Método de pago
            </p>

            {summary.payment_method ? (
              <div className="mt-3 flex items-center gap-3">
                <div className="flex size-9 shrink-0 items-center justify-center rounded-md border">
                  <CardBrandIcon
                    brand={summary.payment_method.brand}
                    className="size-7"
                  />
                </div>

                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">
                    {capitalize(
                      summary.payment_method.brand
                      ?? 'Tarjeta',
                    )}{' '}
                    ••••{' '}
                    {summary.payment_method.last4
                      ?? '0000'}
                  </p>

                  <p className="mt-0.5 text-xs text-muted-foreground">
                    Vence{' '}
                    {summary.payment_method.exp_month
                      ?? '00'}
                    /
                    {summary.payment_method.exp_year
                      ?? '0000'}
                  </p>
                </div>

                <Button
                  size="sm"
                  variant="outline"
                  disabled={
                    pending !== null
                    || !summary.actions.can_manage
                  }
                  onClick={onOpenPortal}
                >
                  Cambiar
                </Button>
              </div>
            ) : (
              <p className="mt-3 text-sm text-muted-foreground">
                Sin método registrado.
              </p>
            )}
          </div>

          <div className="p-5">
            <p className="text-xs font-medium text-muted-foreground">
              Datos de facturación
            </p>

            <dl className="mt-3 divide-y">
              <BillingDetail
                icon={UserIcon}
                label="Razón social"
                value={summary.billing_details?.name}
              />

              <BillingDetail
                icon={MailIcon}
                label="Correo"
                value={summary.billing_details?.email}
              />

              <BillingDetail
                icon={PhoneIcon}
                label="Teléfono"
                value={summary.billing_details?.phone}
              />

              <BillingDetail
                icon={MapPinIcon}
                label="Dirección"
                value={formatAddress(summary)}
              />
            </dl>
          </div>
        </div>
      </Card>

      <Card className="gap-0 overflow-hidden py-0 shadow-none">
        <header className="flex items-start justify-between gap-4 border-b px-5 py-4">
          <div>
            <h2 className="text-sm font-semibold">
              Facturas
            </h2>

            <p className="mt-1 text-xs text-muted-foreground">
              {invoiceQuery.isPending
                ? 'Cargando comprobantes...'
                : getInvoiceCountLabel(invoices.length)}
            </p>
          </div>

          {summary.stripe.customer_exists
            && invoices.length > 0 && (
              <Button
                size="sm"
                variant="ghost"
                onClick={onOpenPortal}
              >
                Ver todas
                <ExternalLinkIcon aria-hidden />
              </Button>
            )}
        </header>

        {invoiceQuery.isPending ? (
          <InvoicesSkeleton />
        ) : invoiceQuery.isError ? (
          <div className="p-5 text-center">
            <p className="text-sm font-medium">
              No pudimos cargar las facturas
            </p>

            <Button
              size="sm"
              variant="outline"
              className="mt-3"
              onClick={() =>
                void invoiceQuery.refetch()
              }
            >
              Reintentar
            </Button>
          </div>
        ) : invoices.length > 0 ? (
          <>
            <div className="hidden grid-cols-[minmax(0,1fr)_8rem_7rem_7rem_2rem] gap-3 border-b px-4 py-2 text-[11px] font-medium text-muted-foreground md:grid">
              <span>Factura</span>
              <span>Fecha</span>
              <span>Estado</span>
              <span className="text-right">
                Total
              </span>
              <span />
            </div>

            {invoices.map((invoice) => (
              <InvoiceRow
                key={invoice.id}
                invoice={invoice}
              />
            ))}

            {invoiceQuery.isFetchingNextPage && (
              <InvoiceLoadingRow />
            )}

            {invoiceQuery.hasNextPage && (
              <div className="border-t p-3 text-center">
                <Button
                  size="sm"
                  variant="outline"
                  disabled={invoiceQuery.isFetching}
                  onClick={() =>
                    void invoiceQuery.fetchNextPage()
                  }
                >
                  {invoiceQuery.isFetchingNextPage
                    ? (
                      <>
                        <Loader2Icon
                          className="animate-spin"
                          aria-hidden
                        />
                        Cargando...
                      </>
                    )
                    : 'Cargar más'}
                </Button>
              </div>
            )}
          </>
        ) : (
          <div className="p-6 text-center">
            <FileTextIcon
              className="mx-auto size-4 text-muted-foreground"
              aria-hidden
            />

            <p className="mt-2 text-sm font-medium">
              Aún no hay facturas
            </p>

            <p className="mt-1 text-xs text-muted-foreground">
              Aparecerán después de tu primer cobro.
            </p>
          </div>
        )}
      </Card>

      {isOnPaidPlan && !isCancelling && (
        <Card className="gap-0 overflow-hidden py-0 shadow-none">
          <header className="border-b px-5 py-4">
            <h2 className="text-sm font-semibold">
              Cancelar suscripción
            </h2>

            <p className="mt-1 text-xs text-muted-foreground">
              Seguirás teniendo acceso hasta finalizar
              el periodo pagado.
            </p>
          </header>

          <div className="flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
            <p className="max-w-xl text-xs leading-5 text-muted-foreground">
              Después del periodo actual tu negocio
              cambiará automáticamente al plan gratuito.
            </p>

            <Button
              size="sm"
              variant="outline"
              className="self-start text-destructive hover:text-destructive sm:self-auto"
              disabled={
                pending !== null
                || !summary.actions.can_manage
              }
              onClick={onCancel}
            >
              {pending === 'cancel'
                ? 'Cancelando...'
                : 'Cancelar suscripción'}
            </Button>
          </div>
        </Card>
      )}
    </div>
  )
}

function SummaryValue({
  label,
  value,
}: {
  label: string
  value: string
}) {
  return (
    <div>
      <p className="text-xs text-muted-foreground">
        {label}
      </p>

      <p className="mt-1 text-sm font-medium">
        {value}
      </p>
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
    <div className="grid gap-1 py-2.5 sm:grid-cols-[8rem_minmax(0,1fr)]">
      <dt className="flex items-center gap-2 text-xs text-muted-foreground">
        <Icon
          className="size-3.5"
          aria-hidden
        />
        {label}
      </dt>

      <dd className="text-sm font-medium">
        {value || 'Sin registrar'}
      </dd>
    </div>
  )
}

function InvoiceRow({
  invoice,
}: {
  invoice: Invoice
}) {
  const downloadUrl =
    invoice.pdf ?? invoice.url

  const isPaid =
    invoice.status === 'paid'

  const statusLabel =
    invoice.status_label
    || {
      paid: 'Pagada',
      open: 'Pendiente',
      draft: 'Borrador',
      void: 'Anulada',
      uncollectible: 'Incobrable',
    }[invoice.status]
    || invoice.status

  return (
    <div className="grid gap-2 border-b px-4 py-2.5 last:border-b-0 md:grid-cols-[minmax(0,1fr)_8rem_7rem_7rem_2rem] md:items-center md:gap-3">
      <div className="min-w-0">
        <p className="truncate text-xs font-medium">
          {invoice.number
            ?? 'Factura de suscripción'}
        </p>

        <p className="mt-0.5 truncate text-[11px] text-muted-foreground">
          {invoice.plan_name || '—'}
        </p>
      </div>

      <p className="text-xs text-muted-foreground">
        {formatDate(invoice.date)}
      </p>

      <Badge
        variant={isPaid ? 'success' : 'outline'}
      >
        {isPaid && (
          <CheckIcon aria-hidden />
        )}
        {statusLabel}
      </Badge>

      <p className="text-xs font-medium md:text-right">
        {formatMoney(invoice.total)}
      </p>

      {downloadUrl ? (
        <Button
          asChild
          size="icon-sm"
          variant="ghost"
        >
          <a
            href={downloadUrl}
            target="_blank"
            rel="noreferrer"
          >
            <DownloadIcon aria-hidden />
          </a>
        </Button>
      ) : (
        <span />
      )}
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
    pending: {
      label: 'Pendiente',
      variant: 'outline',
    },
    suspended: {
      label: 'Solo consulta',
      variant: 'outline',
    },
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

  const value =
    config[status]

  return (
    <Badge variant={value.variant}>
      {value.label}
    </Badge>
  )
}

function BillingDetailsSkeleton() {
  return (
    <div className="space-y-5">
      <Skeleton className="h-52 rounded-xl" />
      <Skeleton className="h-52 rounded-xl" />
      <Skeleton className="h-44 rounded-xl" />
    </div>
  )
}

function InvoicesSkeleton() {
  return (
    <div>
      {Array.from({
        length: 4,
      }).map((_, index) => (
        <div
          key={index}
          className="flex items-center justify-between border-b px-4 py-3 last:border-b-0"
        >
          <div className="space-y-1.5">
            <Skeleton className="h-3 w-28" />
            <Skeleton className="h-2.5 w-20" />
          </div>

          <Skeleton className="h-5 w-16" />
        </div>
      ))}
    </div>
  )
}

function InvoiceLoadingRow() {
  return (
    <div className="flex items-center justify-between border-b px-4 py-3">
      <Skeleton className="h-3 w-28" />
      <Skeleton className="h-5 w-16" />
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
    return 'No disponible'
  }

  return new Date(value)
    .toLocaleDateString(
      'es-MX',
      {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
      },
    )
}

function formatMoney(
  value: string | number,
) {
  const amount =
    Number(value)

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

function capitalize(
  value: string,
) {
  return value
    ? value.charAt(0).toUpperCase()
    + value.slice(1)
    : ''
}

function statusText(
  value: SubscriptionStatus,
) {
  return {
    active: 'Activa',
    pending: 'Pendiente',
    suspended: 'Solo consulta',
    trial: 'Prueba',
    past_due: 'Pago pendiente',
    cancelled: 'Cancelada',
  }[value]
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

  return `${count} comprobantes`
}
