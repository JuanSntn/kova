import { type FormEvent, type ReactNode, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useParams } from '@tanstack/react-router'
import { Loader2Icon } from 'lucide-react'
import { api, getCsrfToken } from '@/lib/api'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { getPublicCatalogs } from './sat-catalog-api'

type PublicTicketItem = {
  name: string
  quantity: number
  unit_price: string
  line_total: string
  tax_base: string
  tax_amount: string
  tax_name: string
  tax_code: string
  tax_factor_type: string
  tax_rate: string
}

type PublicTicket = {
  business: string
  folio: string
  paid_at: string
  tax_base_total: string
  tax_total: string
  total: string
  currency: string
  expires_at: string
  eligible: boolean
  items: PublicTicketItem[]
  invoice: PublicInvoice | null
}

type PublicInvoice = {
  id: string
  series: string
  folio: number
  uuid: string | null
  status: string
  total: string
  issued_at: string | null
  downloads: {
    xml: string | null
    pdf: string | null
  } | null
}

export function PublicInvoicePage() {
  const { token } = useParams({ strict: false }) as {
    token: string
  }

  const [retryingFailedInvoice, setRetryingFailedInvoice] =
    useState(false)

  const query = useQuery({
    queryKey: ['public-invoice', token],
    queryFn: () => getTicket(token),
    retry: false,
    refetchInterval: (state) =>
      ['queued', 'stamping', 'reconciliation_required'].includes(
        state.state.data?.invoice?.status ?? '',
      )
        ? 5000
        : false,
  })

  const mutation = useMutation({
    mutationFn: (payload: Record<string, string>) =>
      requestInvoice(token, payload),
    onSuccess: async () => {
      setRetryingFailedInvoice(false)
      await query.refetch()
    },
  })

  return (
    <main className="min-h-screen bg-[#f7f7f8]">
      <div className="mx-auto flex min-h-screen w-full max-w-5xl flex-col px-4 sm:px-5 lg:px-6">
        <header className="flex h-14 items-center border-b">
          <div className="flex items-baseline gap-2">
            <span className="text-base font-semibold tracking-tight">
              Kova
            </span>

            <span className="text-xs text-muted-foreground">
              Facturación
            </span>
          </div>
        </header>

        <div className="flex flex-1 items-center py-5 lg:py-8">
          {query.isPending && (
            <LoadingState />
          )}

          {query.isError && (
            <ErrorState
              error={readError(query.error)}
              onRetry={() => query.refetch()}
            />
          )}

          {query.data && (
            <Card className="grid w-full overflow-hidden border bg-background p-0 shadow-sm lg:grid-cols-[0.82fr_1.18fr]">
              <TicketSummary
                ticket={query.data}
              />

              <section className="p-5 sm:p-6 lg:p-7 xl:p-8">
                {query.data.invoice &&
                  !(
                    query.data.invoice.status === 'failed' &&
                    retryingFailedInvoice
                  ) ? (
                  <InvoiceState
                    invoice={query.data.invoice}
                    onRetry={
                      query.data.invoice.status === 'failed'
                        ? () => setRetryingFailedInvoice(true)
                        : undefined
                    }
                  />
                ) : (
                  <InvoiceForm
                    token={token}
                    ticket={query.data}
                    busy={mutation.isPending}
                    error={readError(
                      mutation.error,
                    )}
                    retrying={
                      query.data.invoice?.status === 'failed'
                    }
                    onSubmit={(data) =>
                      mutation.mutate(data)
                    }
                  />
                )}
              </section>
            </Card>
          )}
        </div>

        <footer className="flex min-h-12 items-center justify-between gap-4 border-t py-3 text-xs text-muted-foreground">
          <span>
            Kova
          </span>

          <span className="text-right">
            Tus datos se utilizan únicamente para generar tu CFDI.
          </span>
        </footer>
      </div>
    </main>
  )
}

function TicketSummary({
  ticket,
}: {
  ticket: PublicTicket
}) {
  return (
    <aside className="border-b bg-muted/25 p-5 sm:p-6 lg:border-r lg:border-b-0 lg:p-7 xl:p-8">
      <div>
        <p className="text-xs font-medium uppercase tracking-[0.18em] text-muted-foreground">
          Compra localizada
        </p>

        <h1 className="mt-3 text-xl font-semibold tracking-tight">
          {ticket.business}
        </h1>

        <p className="mt-1.5 text-sm leading-5 text-muted-foreground">
          Revisa el detalle de tu compra y los impuestos incluidos antes de generar tu factura.
        </p>
      </div>

      <div className="mt-5 grid grid-cols-2 gap-x-4 border-y py-3 text-sm">
        <div>
          <p className="text-xs text-muted-foreground">
            Ticket
          </p>
          <p className="mt-1 font-medium">
            {ticket.folio}
          </p>
        </div>

        <div className="text-right">
          <p className="text-xs text-muted-foreground">
            Fecha
          </p>
          <p className="mt-1 font-medium">
            {formatDate(ticket.paid_at)}
          </p>
        </div>
      </div>

      <section className="mt-5">
        <div className="flex items-center justify-between gap-4">
          <h2 className="text-sm font-semibold">
            Detalle de compra
          </h2>

          <span className="text-xs text-muted-foreground">
            {ticket.items.length}{' '}
            {ticket.items.length === 1
              ? 'producto'
              : 'productos'}
          </span>
        </div>

        <div className="mt-2.5 divide-y rounded-lg border bg-background/70">
          {ticket.items.map((item, index) => (
            <div
              key={`${item.name}-${index}`}
              className="px-3.5 py-2.5"
            >
              <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">
                    {item.name}
                  </p>

                  <p className="mt-0.5 text-xs text-muted-foreground">
                    {item.quantity} ×{' '}
                    {money(
                      Number(item.unit_price),
                      ticket.currency,
                    )}
                  </p>
                </div>

                <p className="shrink-0 text-sm font-semibold tabular-nums">
                  {money(
                    Number(item.line_total),
                    ticket.currency,
                  )}
                </p>
              </div>

              <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
                <span>
                  Base{' '}
                  {money(
                    Number(item.tax_base),
                    ticket.currency,
                  )}
                </span>

                <span>
                  {taxLabel(item)}{' '}
                  {money(
                    Number(item.tax_amount),
                    ticket.currency,
                  )}
                </span>
              </div>
            </div>
          ))}
        </div>
      </section>

      <div className="mt-4 rounded-lg border bg-background px-3.5">
        <SummaryRow
          label="Subtotal antes de impuestos"
          value={money(
            Number(ticket.tax_base_total),
            ticket.currency,
          )}
        />

        <SummaryRow
          label="Impuestos incluidos"
          value={money(
            Number(ticket.tax_total),
            ticket.currency,
          )}
        />

        <SummaryRow
          label="Total pagado"
          value={money(
            Number(ticket.total),
            ticket.currency,
          )}
          strong
        />
      </div>

      <div className="mt-4 flex items-center justify-between gap-4 text-xs text-muted-foreground">
        <span>Disponible para facturar hasta</span>
        <span className="font-medium text-foreground">
          {formatDate(ticket.expires_at)}
        </span>
      </div>

      {!ticket.eligible && (
        <Alert
          variant="destructive"
          className="mt-6"
        >
          <AlertDescription>
            Este ticket ya no está disponible para facturación.
          </AlertDescription>
        </Alert>
      )}
    </aside>
  )
}

function InvoiceForm({
  token,
  ticket,
  busy,
  error,
  retrying = false,
  onSubmit,
}: {
  token: string
  ticket: PublicTicket
  busy: boolean
  error: string | null
  retrying?: boolean
  onSubmit: (
    data: Record<string, string>,
  ) => void
}) {
  const [rfc, setRfc] =
    useState('')

  const [regime, setRegime] =
    useState('')

  const [use, setUse] =
    useState('')

  const catalogs = useQuery({
    queryKey: [
      'public-sat-catalogs',
      token,
      rfc,
      regime,
    ],
    queryFn: () =>
      getPublicCatalogs(
        token,
        rfc,
        regime,
      ),
    enabled:
      [12, 13].includes(
        rfc.length,
      ),
    retry: false,
  })

  const selectedRegime =
    catalogs.data?.data.tax_regimes.find(
      (entry) => entry.code === regime,
    )

  const selectedUse =
    catalogs.data?.data.cfdi_uses.find(
      (entry) => entry.code === use,
    )

  function submit(
    event:
      FormEvent<HTMLFormElement>,
  ) {
    event.preventDefault()

    onSubmit(
      Object.fromEntries(
        new FormData(
          event.currentTarget,
        ),
      ) as Record<
        string,
        string
      >,
    )
  }

  return (
    <div className="mx-auto w-full max-w-lg">
      <div className="mb-5">
        <p className="text-xs font-medium uppercase tracking-[0.18em] text-muted-foreground">
          Datos fiscales
        </p>

        <h2 className="mt-2 text-xl font-semibold tracking-tight">
          {retrying
            ? 'Revisa tus datos fiscales'
            : 'Genera tu factura'}
        </h2>

        <p className="mt-1.5 max-w-xl text-sm leading-5 text-muted-foreground">
          {retrying
            ? 'Verifica que tus datos coincidan con tu constancia de situación fiscal antes de intentarlo nuevamente.'
            : 'Captura tus datos tal como aparecen en tu constancia de situación fiscal.'}
        </p>
      </div>

      <form
        className="space-y-4"
        onSubmit={submit}
      >
        {error && (
          <Alert variant="destructive">
            <AlertDescription>
              {error}
            </AlertDescription>
          </Alert>
        )}

        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label="RFC"
            description="12 o 13 caracteres."
          >
            <Input
              required
              name="rfc"
              maxLength={13}
              autoCapitalize="characters"
              autoComplete="off"
              value={rfc}
              className="h-9 uppercase"
              onChange={(event) => {
                setRfc(
                  event.target.value
                    .toUpperCase()
                    .replace(
                      /\s/g,
                      '',
                    ),
                )

                setRegime('')
                setUse('')
              }}
            />
          </Field>

          <Field
            label="Código postal fiscal"
            description="El registrado ante el SAT."
          >
            <Input
              required
              name="postal_code"
              maxLength={5}
              inputMode="numeric"
              autoComplete="postal-code"
              className="h-9"
            />
          </Field>
        </div>

        <Field
          label="Nombre o razón social"
          description="Escríbelo como aparece en tu constancia fiscal."
        >
          <Input
            required
            name="legal_name"
            maxLength={254}
            autoComplete="organization"
            className="h-9"
          />
        </Field>

        <div className="grid min-w-0 gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
          <Field
            label="Régimen fiscal"
            description={
              catalogs.isFetching
                ? 'Consultando catálogo SAT...'
                : 'Selecciona tu régimen fiscal.'
            }
          >
            <input
              type="hidden"
              name="tax_regime_code"
              value={regime}
            />

            <Select
              value={regime}
              disabled={
                ![12, 13].includes(
                  rfc.length,
                ) ||
                catalogs.isFetching
              }
              onValueChange={(
                value,
              ) => {
                setRegime(
                  value,
                )
                setUse('')
              }}
            >
              <SelectTrigger className="h-9 w-full min-w-0 overflow-hidden text-left [&>span]:min-w-0 [&>span]:truncate">
                <SelectValue placeholder="Selecciona una opción">
                  {selectedRegime
                    ? `${selectedRegime.code} · ${selectedRegime.description}`
                    : undefined}
                </SelectValue>
              </SelectTrigger>

              <SelectContent>
                {catalogs.data?.data.tax_regimes.map(
                  (
                    entry,
                  ) => (
                    <SelectItem
                      key={
                        entry.code
                      }
                      value={
                        entry.code
                      }
                    >
                      {entry.code}
                      {' · '}
                      {entry.description}
                    </SelectItem>
                  ),
                )}
              </SelectContent>
            </Select>
          </Field>

          <Field
            label="Uso de CFDI"
            description="Se habilita después de elegir tu régimen."
          >
            <input
              type="hidden"
              name="cfdi_use_code"
              value={use}
            />

            <Select
              value={use}
              disabled={
                !regime ||
                catalogs.isFetching
              }
              onValueChange={
                setUse
              }
            >
              <SelectTrigger className="h-9 w-full min-w-0 overflow-hidden text-left [&>span]:min-w-0 [&>span]:truncate">
                <SelectValue placeholder="Selecciona una opción">
                  {selectedUse
                    ? `${selectedUse.code} · ${selectedUse.description}`
                    : undefined}
                </SelectValue>
              </SelectTrigger>

              <SelectContent>
                {catalogs.data?.data.cfdi_uses.map(
                  (
                    entry,
                  ) => (
                    <SelectItem
                      key={
                        entry.code
                      }
                      value={
                        entry.code
                      }
                    >
                      {entry.code}
                      {' · '}
                      {entry.description}
                    </SelectItem>
                  ),
                )}
              </SelectContent>
            </Select>
          </Field>
        </div>

        <Field
          label="Correo electrónico"
          description="Ahí podrás recibir información relacionada con tu factura."
        >
          <Input
            required
            name="email"
            type="email"
            autoComplete="email"
            className="h-9"
          />
        </Field>

        <div className="border-t pt-4">
          <Button
            className="h-10 w-full sm:w-auto sm:min-w-40"
            type="submit"
            disabled={
              !ticket.eligible ||
              busy ||
              !regime ||
              !use
            }
          >
            {busy ? (
              <>
                <Loader2Icon className="animate-spin" />
                Generando...
              </>
            ) : retrying ? (
              'Intentar nuevamente'
            ) : (
              'Generar factura'
            )}
          </Button>
        </div>
      </form>
    </div>
  )
}

function InvoiceState({
  invoice,
  onRetry,
}: {
  invoice: PublicInvoice
  onRetry?: () => void
}) {
  const done =
    invoice.status ===
    'stamped'

  const failed =
    invoice.status ===
    'failed'

  return (
    <div className="mx-auto w-full max-w-lg">
      <p className="text-xs font-medium uppercase tracking-[0.18em] text-muted-foreground">
        Estado de la factura
      </p>

      <h2 className="mt-2 text-xl font-semibold tracking-tight">
        {done
          ? 'Factura emitida'
          : failed
            ? 'No se pudo emitir'
            : 'Estamos procesando tu factura'}
      </h2>

      <p className="mt-3 text-sm leading-6 text-muted-foreground">
        {done
          ? 'Tu CFDI fue generado correctamente. Puedes descargar los archivos disponibles.'
          : failed
            ? 'Ocurrió un error al generar tu factura. Revisa tus datos fiscales e intenta nuevamente. Si el problema continúa, por favor comunícate con soporte.'
            : 'La solicitud fue recibida. Esta página se actualizará automáticamente cuando termine el proceso.'}
      </p>

      {failed && (
        <Alert className="mt-4">
          <AlertDescription>
            Si necesitas ayuda, escríbenos a{' '}
            <a
              href="mailto:soporte@kova.mx"
              className="font-medium text-foreground underline underline-offset-4"
            >
              soporte@kova.mx
            </a>
          </AlertDescription>
        </Alert>
      )}

      <div className="mt-5 divide-y border-y">
        <SummaryRow
          label="Serie y folio"
          value={formatFiscalNumber(invoice.series, invoice.folio)}
        />

        <SummaryRow
          label="Total"
          value={money(
            Number(
              invoice.total,
            ),
            'MXN',
          )}
        />

        {invoice.uuid && (
          <SummaryRow
            label="UUID fiscal"
            value={invoice.uuid}
          />
        )}
      </div>

      {failed && onRetry && (
        <div className="mt-4 flex flex-wrap gap-2">
          <Button
            type="button"
            onClick={onRetry}
          >
            Revisar datos e intentar de nuevo
          </Button>

          <Button
            asChild
            variant="outline"
          >
            <a href="mailto:soporte@kova.mx">
              Contactar soporte
            </a>
          </Button>
        </div>
      )}

      {done &&
        invoice.downloads && (
          <div className="mt-4 flex flex-wrap gap-2">
            {invoice.downloads.pdf && (
              <Button asChild>
                <a
                  href={
                    invoice.downloads.pdf
                  }
                >
                  Descargar PDF
                </a>
              </Button>
            )}

            {invoice.downloads.xml && (
              <Button
                asChild
                variant="outline"
              >
                <a
                  href={
                    invoice.downloads.xml
                  }
                >
                  Descargar XML
                </a>
              </Button>
            )}
          </div>
        )}

      {!done &&
        !failed && (
          <div className="mt-4 flex items-center gap-2 text-sm text-muted-foreground">
            <Loader2Icon className="size-4 animate-spin" />

            <span>
              Actualizando estado...
            </span>
          </div>
        )}
    </div>
  )
}

function LoadingState() {
  return (
    <Card className="flex min-h-[360px] w-full items-center justify-center border bg-background shadow-sm">
      <div className="text-center">
        <Loader2Icon className="mx-auto size-5 animate-spin text-muted-foreground" />

        <p className="mt-3 text-sm text-muted-foreground">
          Consultando tu ticket...
        </p>
      </div>
    </Card>
  )
}

function ErrorState({
  error,
  onRetry,
}: {
  error: string | null
  onRetry: () => void
}) {
  return (
    <Card className="mx-auto w-full max-w-lg border bg-background p-8 shadow-sm">
      <p className="text-xs font-medium uppercase tracking-[0.18em] text-muted-foreground">
        Ticket no disponible
      </p>

      <h1 className="mt-3 text-xl font-semibold tracking-tight">
        No pudimos localizar esta compra
      </h1>

      <p className="mt-1.5 text-sm leading-5 text-muted-foreground">
        {error ?? 'El enlace puede ser incorrecto, haber vencido o el ticket ya no estar disponible para facturación.'}
      </p>

      <div className="mt-4 flex flex-wrap gap-2">
        <Button
          type="button"
          onClick={onRetry}
        >
          Intentar de nuevo
        </Button>

        <Button
          asChild
          variant="outline"
        >
          <a href="/factura">
            Ingresar otro código
          </a>
        </Button>
      </div>
    </Card>
  )
}

function Field({
  label,
  description,
  children,
}: {
  label: string
  description?: string
  children: ReactNode
}) {
  return (
    <div className="min-w-0 space-y-1.5">
      <Label>
        {label}
      </Label>

      {children}

      {description && (
        <p className="text-xs leading-4 text-muted-foreground">
          {description}
        </p>
      )}
    </div>
  )
}

function SummaryRow({
  label,
  value,
  strong = false,
}: {
  label: string
  value: string
  strong?: boolean
}) {
  return (
    <div className="flex items-start justify-between gap-5 py-3 text-sm">
      <span className="text-muted-foreground">
        {label}
      </span>

      <span
        className={
          strong
            ? 'text-right font-semibold'
            : 'break-all text-right font-medium'
        }
      >
        {value}
      </span>
    </div>
  )
}

async function getTicket(
  token: string,
) {
  return (
    await api.get<{
      data: PublicTicket
    }>(
      `/api/public/factura/${encodeURIComponent(
        token,
      )}`,
    )
  ).data.data
}

async function requestInvoice(
  token: string,
  payload:
    Record<string, string>,
) {
  await getCsrfToken()

  return (
    await api.post(
      `/api/public/factura/${encodeURIComponent(
        token,
      )}`,
      payload,
      {
        headers: {
          'Idempotency-Key':
            crypto.randomUUID(),
        },
      },
    )
  ).data
}

function taxLabel(
  item: PublicTicketItem,
): string {
  if (item.tax_factor_type === 'exempt') {
    return 'Exento'
  }

  const rate = Number(item.tax_rate)
  const formattedRate = Number.isInteger(rate)
    ? rate.toFixed(0)
    : rate.toString()

  if (item.tax_code.toLowerCase() === 'iva') {
    return `IVA ${formattedRate}% incluido`
  }

  return `${item.tax_name} ${formattedRate}% incluido`
}

function formatFiscalNumber(
  series: string,
  folio: number,
): string {
  return `${series}-${String(folio).padStart(6, '0')}`
}

function money(
  value: number,
  currency: string,
) {
  return new Intl.NumberFormat(
    'es-MX',
    {
      style:
        'currency',

      currency,
    },
  ).format(
    value,
  )
}

function formatDate(
  value: string,
) {
  return new Intl.DateTimeFormat(
    'es-MX',
    {
      day:
        '2-digit',

      month:
        'short',

      year:
        'numeric',
    },
  ).format(
    new Date(
      value,
    ),
  )
}

function readError(
  error: unknown,
) {
  if (
    typeof error ===
    'object' &&
    error &&
    'response' in error
  ) {
    const response = (
      error as {
        response?: {
          status?: number
          data?: {
            message?: string
            retry_after?: number
          }
        }
      }
    ).response

    if (response?.status === 429) {
      const seconds = Math.max(
        1,
        Number(response.data?.retry_after ?? 60),
      )

      return `Has realizado varias solicitudes. Intenta nuevamente en ${seconds} segundos.`
    }

    return response?.data?.message
      ?? 'No pudimos solicitar la factura.'
  }

  return error
    ? 'No pudimos solicitar la factura.'
    : null
}
