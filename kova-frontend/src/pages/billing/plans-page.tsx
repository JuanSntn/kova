import {
  useMemo,
  useState,
} from 'react'

import {
  CheckIcon,
} from 'lucide-react'

import {
  Badge,
} from '@/components/ui/badge'

import {
  Button,
} from '@/components/ui/button'

import {
  Card,
} from '@/components/ui/card'

import type {
  BillingInterval,
  BillingPlan,
  BillingSummary,
  OfferPrice,
} from '@/features/billing/api'

import {
  money,
} from '@/features/billing/presentation'

export function PlansPage({
  offers,
  summary,
  disabled,
  onSelect,
}: {
  offers: BillingPlan[]

  summary: BillingSummary

  disabled: boolean

  onSelect: (
    plan: BillingPlan,
    price: OfferPrice,
  ) => void
}) {
  const [
    interval,
    setInterval,
  ] =
    useState<BillingInterval>(
      summary.contract
        ?.interval
      ?? 'month',
    )

  const currentPlan =
    offers.find(
      (plan) =>
        plan.code
        === summary.current_plan,
    )

  const annualAvailable =
    offers.some(
      (plan) =>
        plan.prices.some(
          (price) =>
            price.interval
            === 'year'
            && price.available,
        ),
    )

  const sortedOffers =
    useMemo(
      () =>
        [
          ...offers,
        ].sort(
          (
            a,
            b,
          ) =>
            a.tier
            - b.tier,
        ),
      [
        offers,
      ],
    )

  return (
    <section className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-muted-foreground">
          Compara los planes disponibles
          para tu negocio.
        </p>

        <div className="flex rounded-lg border p-1">
          <Button
            size="sm"
            variant={
              interval === 'month'
                ? 'secondary'
                : 'ghost'
            }
            onClick={() =>
              setInterval(
                'month',
              )
            }
          >
            Mensual
          </Button>

          <Button
            size="sm"
            variant={
              interval === 'year'
                ? 'secondary'
                : 'ghost'
            }
            disabled={
              !annualAvailable
            }
            onClick={() =>
              setInterval(
                'year',
              )
            }
          >
            Anual
          </Button>
        </div>
      </div>

      {offers.length === 0 ? (
        <Card className="gap-0 py-0 shadow-none">
          <div className="p-5 text-sm text-muted-foreground">
            No hay ofertas publicadas
            por el momento.
          </div>
        </Card>
      ) : (
        <div className="grid gap-4 lg:grid-cols-3">
          {sortedOffers.map(
            (plan) => {
              const price =
                plan.prices.find(
                  (
                    candidate,
                  ) =>
                    candidate
                      .interval
                    === interval,
                )
                ?? (
                  interval
                    === 'month'
                    ? plan.prices.find(
                      (
                        candidate,
                      ) =>
                        candidate
                          .amount
                        === 0,
                    )
                    : undefined
                )

              const current =
                price
                  ?.offer_id
                === summary
                  .contract
                  ?.offer_id

              const behavior =
                getBehavior(
                  plan,
                  price,
                  currentPlan,
                  summary,
                  current,
                )

              return (
                <PlanCard
                  key={
                    plan.version
                  }
                  plan={
                    plan
                  }
                  price={
                    price
                  }
                  current={
                    current
                  }
                  behavior={
                    behavior
                  }
                  disabled={
                    disabled
                  }
                  onSelect={
                    onSelect
                  }
                />
              )
            },
          )}
        </div>
      )}

      <p className="text-xs leading-5 text-muted-foreground">
        Los upgrades dentro de la
        misma periodicidad se aplican
        inmediatamente. Los downgrades
        y cambios de periodicidad se
        aplican al terminar el periodo
        actual.
      </p>
    </section>
  )
}

/*
|--------------------------------------------------------------------------
| Plan card
|--------------------------------------------------------------------------
*/

function PlanCard({
  plan,
  price,
  current,
  behavior,
  disabled,
  onSelect,
}: {
  plan: BillingPlan

  price?: OfferPrice

  current: boolean

  behavior:
  ChangeBehavior

  disabled: boolean

  onSelect: (
    plan: BillingPlan,
    price: OfferPrice,
  ) => void
}) {
  const features =
    buildFeatures(
      plan,
    )

  const unavailable =
    !price
    || !price.available

  return (
    <Card className="flex gap-0 overflow-hidden py-0 shadow-none">
      <header className="flex items-start justify-between gap-3 border-b px-5 py-4">
        <div>
          <h2 className="text-sm font-semibold">
            {plan.name}
          </h2>

          <p className="mt-1 text-xs text-muted-foreground">
            {planDescription(
              plan.code,
            )}
          </p>
        </div>

        {current && (
          <Badge variant="secondary">
            Actual
          </Badge>
        )}
      </header>

      <div className="flex flex-1 flex-col p-5">
        <div>
          {price ? (
            <>
              <p className="text-xl font-semibold tracking-tight">
                {price.amount === 0
                  ? 'Gratis'
                  : money(
                    price.amount,
                    price.currency,
                  )}
              </p>

              {price.amount > 0 && (
                <p className="mt-0.5 text-xs text-muted-foreground">
                  /{' '}
                  {price.interval
                    === 'year'
                    ? 'año'
                    : 'mes'}{' '}
                  + impuestos
                </p>
              )}
            </>
          ) : (
            <p className="text-sm text-muted-foreground">
              Sin oferta disponible
            </p>
          )}
        </div>

        <ul className="mt-5 flex-1 space-y-2.5 border-t pt-4">
          {features.map(
            (
              feature,
            ) => (
              <li
                key={
                  feature
                }
                className="flex gap-2 text-xs leading-5"
              >
                <CheckIcon
                  className="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                  aria-hidden
                />

                <span>
                  {feature}
                </span>
              </li>
            ),
          )}
        </ul>

        {!current
          && price?.available
          && (
            <p className="mt-4 border-t pt-3 text-xs leading-5 text-muted-foreground">
              {behaviorText(
                behavior,
              )}
            </p>
          )}

        <Button
          size="sm"
          className="mt-4 w-full"
          variant={
            current
              ? 'outline'
              : 'default'
          }
          disabled={
            disabled
            || unavailable
            || current
          }
          onClick={() => {
            if (
              price
            ) {
              onSelect(
                plan,
                price,
              )
            }
          }}
        >
          {current
            ? 'Plan actual'
            : unavailable
              ? 'No disponible'
              : behavior
                === 'upgrade'
                ? 'Mejorar plan'
                : behavior
                  === 'period_end'
                  ? 'Programar cambio'
                  : price?.amount
                    === 0
                    ? 'Cambiar a Free'
                    : 'Seleccionar'}
        </Button>
      </div>
    </Card>
  )
}

/*
|--------------------------------------------------------------------------
| Behavior
|--------------------------------------------------------------------------
*/

type ChangeBehavior =
  | 'initial'
  | 'upgrade'
  | 'period_end'
  | 'free'
  | 'current'

function getBehavior(
  plan: BillingPlan,
  price:
    OfferPrice
    | undefined,
  currentPlan:
    BillingPlan
    | undefined,
  summary:
    BillingSummary,
  current: boolean,
): ChangeBehavior {
  if (current) {
    return 'current'
  }

  if (!price) {
    return 'period_end'
  }

  const hasPaidPlan =
    (
      summary.contract
        ?.amount
      ?? 0
    ) > 0

  /*
   * Free -> paid
   */
  if (
    !hasPaidPlan
  ) {
    return 'initial'
  }

  /*
   * Paid -> Free
   */
  if (
    price.amount
    === 0
  ) {
    return 'free'
  }

  /*
   * Cambio de periodicidad:
   * period_end.
   */
  if (
    summary.contract
      ?.interval
    && price.interval
    !== summary.contract
      .interval
  ) {
    return 'period_end'
  }

  /*
   * Misma periodicidad
   * + mayor tier:
   *
   * immediate upgrade.
   */
  if (
    plan.tier
    > (
      currentPlan
        ?.tier
      ?? 0
    )
  ) {
    return 'upgrade'
  }

  return 'period_end'
}

function behaviorText(
  behavior:
    ChangeBehavior,
) {
  if (
    behavior
    === 'upgrade'
  ) {
    return 'Se aplica de inmediato y puede generar un ajuste proporcional.'
  }

  if (
    behavior
    === 'period_end'
    || behavior
    === 'free'
  ) {
    return 'Se aplicará al finalizar tu periodo actual.'
  }

  return 'Se activa después de confirmar el pago.'
}

/*
|--------------------------------------------------------------------------
| Benefits
|--------------------------------------------------------------------------
*/

function buildFeatures(
  plan:
    BillingPlan,
) {
  const features = [
    plan.max_users
      === null
      ? 'Equipo sin límite con roles por área'
      : plan.max_users === 1
        ? 'Solo el dueño'
        : plan.max_users === 5
          ? '5 usuarios: dueño, caja, mesero, cocina y barra'
          : `${plan.max_users} usuarios con acceso por rol`,

    plan.max_products
      === null
      ? 'Productos sin límite'
      : `${plan.max_products} ${plan.max_products
        === 1
        ? 'producto'
        : 'productos'
      }`,

    plan.max_categories
      === null
      ? 'Categorías sin límite'
      : `${plan.max_categories} ${plan.max_categories
        === 1
        ? 'categoría'
        : 'categorías'
      }`,

    plan.included_branches
      === null
      ? 'Sucursales sin límite'
      : `${plan.included_branches} ${plan.included_branches
        === 1
        ? 'sucursal'
        : 'sucursales'
      }`,

    plan.monthly_sales_limit
      === null
      ? 'Ventas mensuales sin límite'
      : `Ventas hasta ${formatSalesLimit(
        plan.monthly_sales_limit,
      )} al mes`,
  ]

  if (plan.additional_branch_price !== null) {
    features.push(
      `Sucursales adicionales por ${formatSalesLimit(
        plan.additional_branch_price,
      )} al mes`,
    )
  }

  if (plan.max_users === null || plan.max_users > 1) {
    features.push(
      'Cada usuario entra con su propio acceso',
      'Ventas y turnos identificados por responsable',
    )
  }

  if (plan.allows_counter_orders) {
    features.push(
      'Punto de venta, menú y modificadores',
      'Resumen e historial de ventas',
    )
  }

  if (plan.allows_cash_shifts) {
    features.push(
      'Caja y turnos por responsable',
    )
  }

  if (plan.allows_preparation) {
    features.push(
      plan.max_preparation_stations === null
        ? 'KDS separados para Cocina y Barra'
        : 'Cocina y Barra trabajan una comanda compartida',
    )
  }

  if (plan.allows_inventory) {
    features.push(
      'Inventario completo: recetas, producción, ajustes y merma',
    )
  }

  if (plan.allows_invoicing) {
    const stamps = plan.included_stamps === null
      ? 'timbres sin límite'
      : `${plan.included_stamps} timbres al mes`

    features.push(
      `Facturación fiscal con ${stamps}`,
    )
  }

  return features
}

/*
|--------------------------------------------------------------------------
| Presentation
|--------------------------------------------------------------------------
*/

function planDescription(
  code: string,
) {
  return {
    free:
      'Para una persona que administra y vende directamente.',

    business:
      'Para un equipo pequeño que necesita control completo de inventario.',

    restaurant:
      'Para operaciones con Cocina y Barra separadas.',
  }[code]
    ?? 'Funciones para tu operación.'
}

function formatSalesLimit(
  value:
    string | number,
) {
  const amount =
    Number(
      value,
    )

  return new Intl.NumberFormat(
    'es-MX',
    {
      style:
        'currency',

      currency:
        'MXN',

      maximumFractionDigits:
        0,
    },
  ).format(
    amount,
  )
}
