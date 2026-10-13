import { useState } from 'react'

import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Input } from '@/components/ui/input'

import type {
  AddonOffer,
  BillingSummary,
  SubscriptionAddon,
} from './api'

import {
  benefit,
  date,
  labels,
  money,
} from './presentation'

export function CommercialSummary({
  summary: s,
  checkoutError,
  disabled,
  onAddon,
  onCancelChange,
  onCancelPendingCheckout,
  onResume,
}: {
  summary: BillingSummary
  checkoutError?: string
  disabled: boolean
  onAddon: (
    offer: AddonOffer,
    quantity: number,
    existing?: SubscriptionAddon,
  ) => void
  onCancelChange: (id: string) => void
  onCancelPendingCheckout: () => void
  onResume: (
    pending:
      BillingSummary['pending_contracts'][number],
  ) => void
}) {
  const contract =
    s.current_subscription

  const hasPending =
    s.pending_contracts.length > 0
    || s.pending_addon_operations.length > 0
    || s.pending_changes.length > 0

  return (
    <div className="space-y-5">
      {contract?.past_due && (
        <Alert variant="destructive">
          <AlertDescription>
            {contract.status === 'suspended'
              ? 'El negocio está en modo de consulta por un pago pendiente.'
              : `Hay un pago pendiente. Tu período de gracia termina el ${date(contract.grace_ends_at)}.`}
          </AlertDescription>
        </Alert>
      )}

      {hasPending && (
        <Card className="gap-0 overflow-hidden py-0 shadow-none">
          <header className="border-b px-5 py-4">
            <h2 className="text-sm font-semibold">
              Actividad pendiente
            </h2>

            <p className="mt-1 text-xs text-muted-foreground">
              Cambios o pagos pendientes de confirmar.
            </p>
          </header>

          <div className="divide-y px-5">
            {s.pending_contracts.map(
              (pending) => (
                <div
                  key={pending.offer_id}
                  className="flex flex-col gap-3 py-3 sm:flex-row sm:items-center sm:justify-between"
                >
                  <div>
                    <p className="text-sm font-medium">
                      Contratación de {pending.name}
                    </p>

                    <p className="mt-0.5 text-xs text-muted-foreground">
                      {pending.message}
                    </p>
                  </div>

                  <div className="flex flex-wrap gap-2">
                    {pending.can_resume && (
                      <Button
                        size="sm"
                        variant="outline"
                        disabled={disabled}
                        onClick={() =>
                          onResume(pending)
                        }
                      >
                        Continuar pago
                      </Button>
                    )}

                    {pending.can_cancel && (
                      <Button
                        size="sm"
                        variant="ghost"
                        disabled={disabled}
                        onClick={onCancelPendingCheckout}
                      >
                        Cancelar intento
                      </Button>
                    )}
                  </div>
                </div>
              ),
            )}

            {s.pending_addon_operations.map(
              (operation, index) => (
                <div
                  key={index}
                  className="flex flex-col gap-3 py-3 sm:flex-row sm:items-center sm:justify-between"
                >
                  <div>
                    <p className="text-sm font-medium">
                      {operation.name
                        ?? 'Complemento'}
                    </p>

                    <p className="mt-0.5 text-xs text-muted-foreground">
                      {operation.quantity}{' '}
                      unidades ·{' '}
                      {operation.status === 'awaiting_payment'
                        ? 'Pago pendiente'
                        : 'Esperando confirmación'}
                    </p>
                  </div>

                  {operation.invoice_url && (
                    <Button
                      asChild
                      size="sm"
                      variant="outline"
                    >
                      <a
                        href={operation.invoice_url}
                        target="_blank"
                        rel="noopener noreferrer"
                      >
                        Revisar pago
                      </a>
                    </Button>
                  )}
                </div>
              ),
            )}

            {s.pending_changes.map(
              (change) => (
                <div
                  key={change.id}
                  className="flex flex-col gap-3 py-3 sm:flex-row sm:items-center sm:justify-between"
                >
                  <div>
                    <p className="text-sm font-medium">
                      {change.target_name
                        ?? 'Cambio de contrato'}
                    </p>

                    <p className="mt-0.5 text-xs text-muted-foreground">
                      {change.status === 'processing'
                        ? 'En proceso'
                        : change.confirmed
                          ? 'Programado'
                          : 'Pendiente'}
                      {' · '}
                      {date(change.effective_at)}
                    </p>
                  </div>

                  <div className="flex flex-wrap gap-2">
                    {change.invoice_url && (
                      <Button
                        asChild
                        size="sm"
                        variant="outline"
                      >
                        <a
                          href={change.invoice_url}
                          target="_blank"
                          rel="noopener noreferrer"
                        >
                          Revisar pago
                        </a>
                      </Button>
                    )}

                    {change.status === 'pending' && (
                      <Button
                        size="sm"
                        variant="ghost"
                        disabled={disabled}
                        onClick={() =>
                          onCancelChange(change.id)
                        }
                      >
                        Cancelar
                      </Button>
                    )}
                  </div>
                </div>
              ),
            )}
          </div>

          {checkoutError && (
            <div className="border-t px-5 py-3">
              <p className="text-xs text-destructive">
                {checkoutError}
              </p>
            </div>
          )}
        </Card>
      )}

      <Card className="gap-0 overflow-hidden py-0 shadow-none">
        <header className="border-b px-5 py-4">
          <h2 className="text-sm font-semibold">
            Beneficios y consumo
          </h2>

          <p className="mt-1 text-xs text-muted-foreground">
            {date(s.usage_period?.starts_at)}
            {' — '}
            {date(s.usage_period?.ends_at)}
          </p>
        </header>

        <div className="p-5">
          <div className="grid gap-x-8 sm:grid-cols-2">
            {Object.entries(s.entitlements)
              .filter(
                ([code]) =>
                  Boolean(labels[code]),
              )
              .map(([code, value]) => (
                <div
                  key={code}
                  className="flex items-center justify-between gap-4 border-b py-2.5 text-sm"
                >
                  <span className="text-xs text-muted-foreground">
                    {labels[code]}
                  </span>

                  <span className="text-sm font-medium">
                    {s.capacity_usage[code] !== undefined
                      ? `${s.capacity_usage[code]} / `
                      : ''}

                    {benefit(code, value)}
                  </span>
                </div>
              ))}
          </div>

          <div className="mt-4 border-t pt-4">
            <p className="text-xs text-muted-foreground">
              Ventas del período
            </p>

            <p className="mt-1 text-sm font-medium">
              {money(s.monthly_sales_consumed)}
            </p>
          </div>

          {s.usage.length > 0 && (
            <div className="mt-4 divide-y border-t">
              {s.usage.map((usage) => (
                <div
                  key={usage.feature}
                  className="flex justify-between gap-4 py-2.5"
                >
                  <span className="text-xs text-muted-foreground">
                    {labels[usage.feature]
                      ?? usage.feature}
                  </span>

                  <span className="text-sm font-medium">
                    {benefit(
                      usage.feature,
                      usage.consumed,
                    )}

                    {usage.included !== null
                      ? ` / ${benefit(
                        usage.feature,
                        usage.included,
                      )}`
                      : ' · Sin límite'}
                  </span>
                </div>
              ))}
            </div>
          )}
        </div>
      </Card>

      {s.billing_components.length > 0 && (
        <Card className="gap-0 overflow-hidden py-0 shadow-none">
          <header className="border-b px-5 py-4">
            <h2 className="text-sm font-semibold">
              Próximos cobros
            </h2>

            <p className="mt-1 text-xs text-muted-foreground">
              Fechas de renovación del plan y extras.
            </p>
          </header>

          <div className="divide-y px-5">
            {s.billing_components.map(
              (component) => (
                <div
                  key={component.role}
                  className="flex items-center justify-between gap-4 py-3"
                >
                  <span className="text-sm">
                    {component.role === 'base'
                      ? 'Plan base'
                      : 'Extras mensuales'}
                  </span>

                  <span className="text-sm font-medium">
                    {date(
                      component.next_billing_at,
                    )}
                  </span>
                </div>
              ),
            )}
          </div>
        </Card>
      )}

      <Card className="gap-0 overflow-hidden py-0 shadow-none">
        <header className="border-b px-5 py-4">
          <h2 className="text-sm font-semibold">
            Complementos
          </h2>

          <p className="mt-1 text-xs text-muted-foreground">
            Amplía las capacidades de tu contrato.
          </p>
        </header>

        {s.available_addons.length === 0 ? (
          <p className="p-5 text-sm text-muted-foreground">
            Tu contrato no dispone de complementos.
          </p>
        ) : (
          <div className="divide-y px-5">
            {s.available_addons.map(
              (offer) => {
                const existing =
                  s.addons.find(
                    (addon) =>
                      addon.code === offer.code,
                  )

                return (
                  <AddonRow
                    key={offer.version}
                    offer={offer}
                    existing={existing}
                    disabled={
                      disabled
                      || s.pending_addon_operations.length > 0
                      || s.pending_changes.length > 0
                      || Boolean(
                        contract?.cancel_at_period_end,
                      )
                      || s.contract?.status !== 'active'
                    }
                    onSave={onAddon}
                  />
                )
              },
            )}
          </div>
        )}
      </Card>
    </div>
  )
}

function AddonRow({
  offer,
  existing,
  disabled,
  onSave,
}: {
  offer: AddonOffer
  existing?: SubscriptionAddon
  disabled: boolean
  onSave: (
    offer: AddonOffer,
    quantity: number,
    existing?: SubscriptionAddon,
  ) => void
}) {
  const [quantity, setQuantity] =
    useState(
      String(existing?.quantity ?? 0),
    )

  const price =
    offer.prices.find(
      (candidate) =>
        candidate.offer_id
        === existing?.offer_id,
    )
    ?? offer.prices[0]

  const valid =
    /^\d+$/.test(quantity)

  const amount =
    valid
      ? Number(quantity)
      : null

  return (
    <div className="grid gap-3 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
      <div>
        <p className="text-sm font-medium">
          {offer.name}
        </p>

        <p className="mt-0.5 text-xs leading-5 text-muted-foreground">
          {price
            ? money(
              existing?.amount
              ?? price.amount,
              price.currency,
            )
            : 'Tarifa no disponible'}

          {offer.mode === 'metered'
            ? ' por unidad excedente'
            : ' por unidad al mes'}

          {existing
            && ` · ${existing.quantity} contratados`}
        </p>
      </div>

      {offer.mode === 'recurring' && (
        <div className="flex items-center gap-2">
          <Input
            className="h-8 w-20"
            type="number"
            min={0}
            max={100}
            value={quantity}
            disabled={
              disabled
              || existing?.status === 'pending'
            }
            onChange={(event) =>
              setQuantity(event.target.value)
            }
          />

          <Button
            size="sm"
            variant="outline"
            disabled={
              disabled
              || existing?.status === 'pending'
              || !price?.available
              || amount === null
              || amount > 100
              || amount === (
                existing?.quantity ?? 0
              )
            }
            onClick={() => {
              if (amount === null) {
                return
              }

              onSave(
                offer,
                amount,
                existing,
              )
            }}
          >
            Revisar
          </Button>
        </div>
      )}
    </div>
  )
}
