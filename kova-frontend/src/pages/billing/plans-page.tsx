import { CheckIcon, ChevronRightIcon } from 'lucide-react'

import { Button } from '@/components/ui/button'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Separator } from '@/components/ui/separator'
import { Skeleton } from '@/components/ui/skeleton'
import type {
  BillingPlan,
  BillingSummary,
  PlanCode,
} from '@/features/billing/api'

type PaidPlanCode = Exclude<PlanCode, 'free'>

type PlansPageProps = {
  onCancelConfirmation: () => void
  onConfirmPlan: (plan: PaidPlanCode) => void
  onSelectPlan: (plan: PaidPlanCode) => void
  pendingPlan: PlanCode | null
  planToConfirm: PaidPlanCode | null
  summary: BillingSummary | null
}

export function PlansPage({
  onCancelConfirmation,
  onConfirmPlan,
  onSelectPlan,
  pendingPlan,
  planToConfirm,
  summary,
}: PlansPageProps) {
  if (!summary) {
    return (
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {[1, 2, 3].map((item) => (
          <Skeleton
            className="h-[25rem] rounded-xl"
            key={item}
          />
        ))}
      </div>
    )
  }

  const currentPlan =
    summary.current_subscription?.plan ?? 'free'

  const confirmationIsUpgrade = planToConfirm
    ? isPlanUpgrade(currentPlan, planToConfirm)
    : false

  const confirmationPending =
    planToConfirm !== null && pendingPlan === planToConfirm

  return (
    <>
      <div className="space-y-6">
        <div className="grid items-stretch gap-4 md:grid-cols-2 xl:grid-cols-3">
          {summary.plans.map((plan) => (
            <PlanCard
              current={currentPlan === plan.code}
              currentPlan={currentPlan}
              disabled={pendingPlan !== null}
              key={plan.code}
              onSelect={onSelectPlan}
              pending={pendingPlan === plan.code}
              plan={plan}
            />
          ))}
        </div>

        <p className="text-xs leading-5 text-muted-foreground">
          Los precios se muestran en pesos mexicanos y no incluyen IVA.
          Los cambios de un plan de pago a otro pueden generar ajustes
          calculados por Stripe.
        </p>
      </div>

      <Dialog
        open={planToConfirm !== null}
        onOpenChange={(open) => {
          if (!open && pendingPlan === null) {
            onCancelConfirmation()
          }
        }}
      >
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>
              {confirmationIsUpgrade
                ? 'Confirma la mejora de plan'
                : 'Confirma el cambio de plan'}
            </DialogTitle>

            <DialogDescription className="text-xs leading-5">
              {planToConfirm && (
                <>
                  {confirmationIsUpgrade
                    ? 'Mejorarás'
                    : 'Cambiarás'}{' '}
                  de{' '}
                  <span className="font-medium text-foreground">
                    {planName(summary, currentPlan)}
                  </span>{' '}
                  a{' '}
                  <span className="font-medium text-foreground">
                    {planName(summary, planToConfirm)}
                  </span>
                  .
                </>
              )}
            </DialogDescription>
          </DialogHeader>

          <div className="rounded-lg border bg-muted/40 p-4">
            <p className="text-sm font-medium">
              Ajuste del periodo actual
            </p>

            <p className="mt-1 text-xs leading-5 text-muted-foreground">
              Stripe calculará automáticamente el importe proporcional.
              Dependiendo del cambio, puede aplicarse un cargo o un
              crédito.
            </p>
          </div>

          <DialogFooter>
            <Button
              variant="outline"
              disabled={pendingPlan !== null}
              onClick={onCancelConfirmation}
            >
              Cancelar
            </Button>

            <Button
              disabled={!planToConfirm || pendingPlan !== null}
              onClick={() => {
                if (planToConfirm) {
                  onConfirmPlan(planToConfirm)
                }
              }}
            >
              {confirmationPending
                ? 'Procesando...'
                : confirmationIsUpgrade
                  ? 'Confirmar mejora'
                  : 'Confirmar cambio'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}

function PlanCard({
  current,
  currentPlan,
  disabled,
  onSelect,
  pending,
  plan,
}: {
  current: boolean
  currentPlan: PlanCode
  disabled: boolean
  onSelect: (plan: PaidPlanCode) => void
  pending: boolean
  plan: BillingPlan
}) {
  const presentation = planPresentation(plan.code)
  const features = planFeatures(plan)
  const isFreePlan = plan.code === 'free'

  return (
    <Card
      className={[
        'h-full gap-0 py-0 shadow-none',
        current ? 'ring-2 ring-primary' : '',
      ].join(' ')}
    >
      <CardHeader className="gap-0 px-4 pb-4 pt-4">
        <div className="flex min-h-12 items-start justify-between gap-3">
          <div>
            <CardTitle className="text-base tracking-tight">
              {plan.name}
            </CardTitle>

            <CardDescription className="mt-0.5 max-w-64 text-xs leading-4">
              {presentation.description}
            </CardDescription>
          </div>

          {current && (
            <span className="shrink-0 rounded-full bg-primary/10 px-2.5 py-1 text-xs font-medium text-primary">
              Plan actual
            </span>
          )}
        </div>

        <div className="mt-4 flex flex-wrap items-baseline gap-x-2 gap-y-1">
          <span className="text-2xl font-semibold tracking-tight">
            {formatMoney(plan.monthly_price)}
          </span>

          <span className="text-xs text-muted-foreground">
            / mes
          </span>

          <span className="ml-auto text-xs text-muted-foreground">
            + IVA
          </span>
        </div>

        {isFreePlan || current ? (
          <Button
            className="mt-4 h-8 w-full"
            variant="outline"
            disabled
          >
            {current
              ? 'Tu plan actual'
              : 'Incluido al registrarte'}
          </Button>
        ) : (
          <Button
            className="mt-4 h-8 w-full"
            disabled={disabled}
            onClick={() =>
              onSelect(plan.code as PaidPlanCode)
            }
          >
            {pending
              ? 'Preparando cambio...'
              : isPlanUpgrade(currentPlan, plan.code)
                ? `Mejorar a ${plan.name}`
                : `Cambiar a ${plan.name}`}

            {!pending && (
              <ChevronRightIcon aria-hidden />
            )}
          </Button>
        )}
      </CardHeader>

      <CardContent className="flex flex-1 flex-col px-4 pb-4">
        <Separator className="mb-4" />

        <p className="text-xs font-medium">
          {presentation.includedTitle}
        </p>

        <ul className="mt-3 space-y-2 text-xs">
          {features.map((feature) => (
            <li
              className="flex items-start gap-2.5"
              key={feature}
            >
              <span className="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full bg-primary/10">
                <CheckIcon
                  className="size-3 text-primary"
                  strokeWidth={2}
                  aria-hidden
                />
              </span>

              <span className="leading-4 text-muted-foreground">
                {feature}
              </span>
            </li>
          ))}
        </ul>

        {plan.additional_stamp_price && (
          <p className="mt-auto border-t pt-4 text-[11px] leading-4 text-muted-foreground">
            Timbre excedente:{' '}
            {formatMoney(plan.additional_stamp_price)} + IVA.
            {plan.additional_branch_price
              ? ` Sucursal adicional: ${formatMoney(
                  plan.additional_branch_price,
                )} + IVA.`
              : ''}
          </p>
        )}
      </CardContent>
    </Card>
  )
}

function planLevel(code: PlanCode) {
  const levels: Record<PlanCode, number> = {
    free: 0,
    business: 1,
    restaurant: 2,
  }

  return levels[code]
}

function isPlanUpgrade(
  currentPlan: PlanCode,
  targetPlan: PlanCode,
) {
  return planLevel(targetPlan) > planLevel(currentPlan)
}

function planPresentation(code: PlanCode) {
  return {
    free: {
      description:
        'Para comenzar con una operación sencilla.',
      includedTitle: 'Funciones esenciales:',
    },
    business: {
      description:
        'Para comercios que necesitan mayor control.',
      includedTitle: 'Todo lo de Free, más:',
    },
    restaurant: {
      description:
        'Para restaurantes con una operación completa.',
      includedTitle: 'Todo lo de Negocio, más:',
    },
  }[code]
}

function planFeatures(plan: BillingPlan) {
  const branchLabel =
    plan.included_branches === 1
      ? '1 sucursal incluida'
      : `${plan.included_branches} sucursales incluidas`

  const limits = [
    plan.max_users === null
      ? 'Usuarios ilimitados'
      : `${plan.max_users} ${
          plan.max_users === 1 ? 'usuario' : 'usuarios'
        }`,
    plan.max_products === null
      ? 'Productos ilimitados'
      : `${plan.max_products.toLocaleString(
          'es-MX',
        )} productos`,
    plan.monthly_sales_limit === null
      ? 'Ventas mensuales sin límite'
      : `Hasta ${formatMoney(
          plan.monthly_sales_limit,
        )} en ventas al mes`,
    branchLabel,
  ]

  if (plan.code === 'free') {
    return [...limits, 'Órdenes de mostrador']
  }

  return [
    ...limits,
    `${plan.included_stamps.toLocaleString(
      'es-MX',
    )} timbres incluidos`,
    plan.allows_inventory && 'Control de inventario',
    plan.allows_invoicing &&
      'Facturación electrónica',
    plan.allows_table_orders && 'Órdenes por mesa',
  ].filter(
    (feature): feature is string => Boolean(feature),
  )
}

function formatMoney(value: string) {
  const amount = Number(value)

  if (!Number.isFinite(amount)) {
    return '$0'
  }

  return new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
    maximumFractionDigits:
      amount % 1 === 0 ? 0 : 2,
  }).format(amount)
}

function planName(
  summary: BillingSummary,
  code?: PlanCode,
) {
  return (
    summary.plans.find((plan) => plan.code === code)
      ?.name ?? 'tu plan actual'
  )
}