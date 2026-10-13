import { useState } from 'react'
import {
  AlertCircleIcon,
  Building2Icon,
  CreditCardIcon,
  ExternalLinkIcon,
  MapPinIcon,
  PencilIcon,
  PlusIcon,
  RotateCcwIcon,
} from 'lucide-react'
import {
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { toast } from 'sonner'

import {
  Alert,
  AlertDescription,
} from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Card,
  CardContent,
} from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import { Skeleton } from '@/components/ui/skeleton'
import { useSession } from '@/features/auth/session-context'
import { queryKeys } from '@/lib/query-keys'

import {
  branchApiErrors,
  cancelAdditionalBranch,
  createBranch,
  enableBranchForPlan,
  getBranches,
  purchaseAdditionalBranch,
  updateBranch,
} from './api'
import { BranchForm } from './branch-form'
import type {
  AdditionalBranchPurchase,
  Branch,
  BranchPayload,
} from './types'

type Editor =
  | { mode: 'create' }
  | { mode: 'edit'; branch: Branch }
  | null

export function BranchesSettingsPage() {
  const { activeTenant } = useSession()
  const tenantId = activeTenant?.id ?? ''
  const queryClient = useQueryClient()

  const [editor, setEditor] =
    useState<Editor>(null)

  const [errors, setErrors] =
    useState<Record<string, string>>({})

  const [purchaseOpen, setPurchaseOpen] =
    useState(false)

  const [cancelOpen, setCancelOpen] =
    useState(false)

  const [retainedBranchIds, setRetainedBranchIds] =
    useState<string[]>([])

  const branchesQuery = useQuery({
    queryKey: queryKeys.branches(tenantId),
    queryFn: getBranches,
    enabled: Boolean(tenantId),
    refetchInterval: (query) =>
      query.state.data?.additionalBranch.pending
        ? 5_000
        : false,
  })

  const saveMutation = useMutation({
    mutationFn: (
      payload: BranchPayload,
    ) =>
      editor?.mode === 'edit'
        ? updateBranch(
          editor.branch.id,
          payload,
        )
        : createBranch(payload),

    onSuccess: async () => {
      toast.success(
        editor?.mode === 'edit'
          ? 'Sucursal actualizada.'
          : 'Sucursal creada.',
      )

      setErrors({})
      setEditor(null)

      await queryClient.invalidateQueries({
        queryKey:
          queryKeys.branches(tenantId),
      })
    },

    onError: (error) => {
      setErrors(
        branchApiErrors(error),
      )
    },
  })

  const enableMutation = useMutation({
    mutationFn: enableBranchForPlan,
    onSuccess: async () => {
      toast.success('Sucursal incluida nuevamente en tu plan.')
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: queryKeys.branches(tenantId) }),
        queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'operable-branches'] }),
      ])
    },
    onError: (error) => {
      toast.error(branchApiErrors(error).form ?? 'No pudimos habilitar la sucursal.')
    },
  })

  const purchaseMutation = useMutation({
    mutationFn: purchaseAdditionalBranch,
    onSuccess: async (purchase) => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: queryKeys.branches(tenantId),
        }),
        queryClient.invalidateQueries({
          queryKey: queryKeys.billing(tenantId),
        }),
      ])

      if (purchase.status === 'confirmed') {
        setPurchaseOpen(false)
        toast.success(
          'Pago confirmado. Ya puedes registrar la nueva sucursal.',
        )
        openCreate()
        return
      }

      setPurchaseOpen(false)
      toast.message(
        'Completa el pago para habilitar el cupo de la sucursal.',
      )
    },
    onError: (error) => {
      toast.error(
        branchApiErrors(error).form
        ?? 'No pudimos preparar el pago.',
      )
    },
  })

  const cancelMutation = useMutation({
    mutationFn: cancelAdditionalBranch,
    onSuccess: async (change) => {
      setCancelOpen(false)
      setRetainedBranchIds([])
      toast.success(
        `La cancelación quedó programada para ${formatDate(change.effectiveAt)}.`,
      )
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: queryKeys.branches(tenantId),
        }),
        queryClient.invalidateQueries({
          queryKey: queryKeys.billing(tenantId),
        }),
      ])
    },
    onError: (error) => {
      toast.error(
        branchApiErrors(error).form
        ?? 'No pudimos programar la cancelación.',
      )
    },
  })

  function openCreate() {
    setErrors({})
    setEditor({
      mode: 'create',
    })
  }

  function openEdit(
    branch: Branch,
  ) {
    setErrors({})
    setEditor({
      mode: 'edit',
      branch,
    })
  }

  function closeEditor() {
    if (saveMutation.isPending) {
      return
    }

    setEditor(null)
    setErrors({})
  }

  if (branchesQuery.isPending) {
    return <BranchesSkeleton />
  }

  const branches =
    branchesQuery.data?.branches ?? []
  const canCreate =
    branchesQuery.data?.capacity.canCreate ?? false
  const additionalBranch =
    branchesQuery.data?.additionalBranch
  const requiredRetainedBranches =
    additionalBranch?.active?.requiredRetainedBranches ?? 0
  const canConfirmCancellation =
    retainedBranchIds.length === requiredRetainedBranches

  function openCancellation() {
    setRetainedBranchIds([])
    setCancelOpen(true)
  }

  function toggleRetainedBranch(branchId: string, checked: boolean) {
    setRetainedBranchIds((current) => {
      if (checked) {
        return current.includes(branchId)
          ? current
          : [...current, branchId]
      }

      return current.filter((id) => id !== branchId)
    })
  }

  return (
    <>
      <div className="w-full space-y-6">
        <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div className="space-y-1">
            <h1 className="text-xl font-semibold tracking-tight">
              Sucursales
            </h1>

            <p className="max-w-2xl text-sm leading-6 text-muted-foreground">
              Administra las ubicaciones donde
              opera tu negocio.
            </p>
          </div>

          {canCreate ? (
            <Button
              type="button"
              onClick={openCreate}
              disabled={saveMutation.isPending}
            >
              <PlusIcon className="size-4" />
              Agregar sucursal
            </Button>
          ) : null}
        </header>

        {branchesQuery.isError ? (
          <Alert variant="destructive">
            <AlertCircleIcon />

            <AlertDescription className="flex flex-wrap items-center justify-between gap-3">
              <span>
                No pudimos cargar las
                sucursales.
              </span>

              <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() =>
                  branchesQuery.refetch()
                }
              >
                Reintentar
              </Button>
            </AlertDescription>
          </Alert>
        ) : null}

        {(!canCreate || additionalBranch?.active) && additionalBranch ? (
          <AdditionalBranchCard
            purchase={additionalBranch}
            onPurchase={() => setPurchaseOpen(true)}
            onCancel={openCancellation}
            purchasing={purchaseMutation.isPending}
          />
        ) : null}

        {branches.length ? (
          <section
            className="space-y-2.5"
            aria-label="Sucursales registradas"
          >
            {branches.map(
              (branch) => (
                <BranchRow
                  key={branch.id}
                  branch={branch}
                  onEdit={() =>
                    openEdit(branch)
                  }
                  onEnable={() => enableMutation.mutate(branch.id)}
                  enabling={enableMutation.isPending && enableMutation.variables === branch.id}
                  canEnable={canCreate}
                />
              ),
            )}
          </section>
        ) : (
          <EmptyBranches
            onCreate={openCreate}
            canCreate={canCreate}
          />
        )}
      </div>

      <Sheet
        open={Boolean(editor)}
        onOpenChange={(open) => {
          if (!open) {
            closeEditor()
          }
        }}
      >
        <SheetContent
          side="right"
          className="flex !w-[96vw] !max-w-[1100px] flex-col gap-0 p-0 sm:!w-[85vw] lg:!w-[72vw] xl:!w-[68vw]"
        >
          <SheetHeader className="border-b px-8 py-6 text-left">
            <SheetTitle>
              {editor?.mode === 'edit'
                ? 'Editar sucursal'
                : 'Nueva sucursal'}
            </SheetTitle>

            <SheetDescription>
              {editor?.mode === 'edit'
                ? 'Actualiza la información de esta ubicación.'
                : 'Registra una nueva ubicación para tu negocio.'}
            </SheetDescription>
          </SheetHeader>

          {editor ? (
            <BranchForm
              key={
                editor.mode === 'edit'
                  ? editor.branch.id
                  : 'new'
              }
              branch={
                editor.mode === 'edit'
                  ? editor.branch
                  : null
              }
              allowsInvoicing={
                branchesQuery.data?.capabilities
                  .allowsInvoicing ?? false
              }
              busy={
                saveMutation.isPending
              }
              errors={errors}
              onCancel={closeEditor}
              onSubmit={(payload) => {
                setErrors({})
                saveMutation.mutate(
                  payload,
                )
              }}
            />
          ) : null}
        </SheetContent>
      </Sheet>

      <Dialog
        open={purchaseOpen}
        onOpenChange={(open) => {
          if (!purchaseMutation.isPending) {
            setPurchaseOpen(open)
          }
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              Contratar sucursal adicional
            </DialogTitle>

            <DialogDescription>
              {additionalBranch?.price
                ? `Agregarás una sucursal a tu suscripción por ${formatPrice(additionalBranch.price.amount, additionalBranch.price.currency)} al mes, más impuestos aplicables. Stripe calculará el importe proporcional del período actual.`
                : 'Prepararemos el pago de la sucursal adicional.'}
            </DialogDescription>
          </DialogHeader>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              disabled={purchaseMutation.isPending}
              onClick={() => setPurchaseOpen(false)}
            >
              Cancelar
            </Button>

            <Button
              type="button"
              disabled={purchaseMutation.isPending}
              onClick={() => purchaseMutation.mutate(undefined)}
            >
              {purchaseMutation.isPending
                ? 'Preparando pago…'
                : 'Continuar al pago'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog
        open={cancelOpen}
        onOpenChange={(open) => {
          if (!cancelMutation.isPending) {
            setCancelOpen(open)
          }
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              Cancelar sucursal adicional
            </DialogTitle>

            <DialogDescription>
              El cupo adicional seguirá disponible hasta el final del período actual. No se elimina ninguna sucursal ahora.
            </DialogDescription>
          </DialogHeader>

          {requiredRetainedBranches > 0 ? (
            <div className="space-y-3">
              <p className="text-sm font-medium">
                Elige las {requiredRetainedBranches} sucursal{requiredRetainedBranches === 1 ? '' : 'es'} que seguirán incluidas al terminar el período.
              </p>

              <div className="space-y-2 rounded-lg border p-3">
                {branches
                  .filter((branch) => branch.billingEnabled)
                  .map((branch) => (
                    <label
                      key={branch.id}
                      className="flex cursor-pointer items-center gap-3 rounded-md px-2 py-1.5 hover:bg-muted/50"
                    >
                      <Checkbox
                        checked={retainedBranchIds.includes(branch.id)}
                        onCheckedChange={(checked) =>
                          toggleRetainedBranch(branch.id, checked === true)
                        }
                        disabled={
                          !retainedBranchIds.includes(branch.id)
                          && retainedBranchIds.length >= requiredRetainedBranches
                        }
                      />
                      <span className="text-sm">{branch.name}</span>
                    </label>
                  ))}
              </div>
            </div>
          ) : null}

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              disabled={cancelMutation.isPending}
              onClick={() => setCancelOpen(false)}
            >
              Volver
            </Button>

            <Button
              type="button"
              variant="destructive"
              disabled={cancelMutation.isPending || !canConfirmCancellation}
              onClick={() => cancelMutation.mutate(retainedBranchIds)}
            >
              {cancelMutation.isPending
                ? 'Programando…'
                : 'Programar cancelación'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}

function AdditionalBranchCard({
  purchase,
  onPurchase,
  onCancel,
  purchasing,
}: {
  purchase: AdditionalBranchPurchase
  onPurchase: () => void
  onCancel: () => void
  purchasing: boolean
}) {
  if (!purchase.available) {
    return (
      <Card className="border-dashed bg-muted/20 py-0 shadow-none">
        <CardContent className="flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 className="text-sm font-semibold">
              Alcanzaste el cupo de sucursales
            </h2>

            <p className="mt-1 text-sm text-muted-foreground">
              Tu plan actual incluye una sucursal. Actualiza a Negocio o Restaurante para agregar más ubicaciones.
            </p>
          </div>
        </CardContent>
      </Card>
    )
  }

  if (purchase.pending) {
    return (
      <Card className="border-primary/20 bg-primary/5 py-0 shadow-none">
        <CardContent className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex gap-3">
            <div className="flex size-9 shrink-0 items-center justify-center rounded-full bg-background">
              <CreditCardIcon className="size-4" />
            </div>

            <div>
              <h2 className="text-sm font-semibold">
                Pago pendiente de sucursal adicional
              </h2>

              <p className="mt-1 text-sm text-muted-foreground">
                Cuando Stripe confirme el pago, se habilitará el cupo para registrar la ubicación.
              </p>
            </div>
          </div>

          {purchase.pending.invoiceUrl ? (
            <Button asChild size="sm">
              <a
                href={purchase.pending.invoiceUrl}
                target="_blank"
                rel="noopener noreferrer"
              >
                Continuar pago
                <ExternalLinkIcon className="size-4" />
              </a>
            </Button>
          ) : null}
        </CardContent>
      </Card>
    )
  }

  if (purchase.active?.effectiveAt) {
    return (
      <Card className="border-dashed bg-muted/20 py-0 shadow-none">
        <CardContent className="flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 className="text-sm font-semibold">
              Cancelación de sucursal adicional programada
            </h2>

            <p className="mt-1 text-sm text-muted-foreground">
              El cupo se reducirá el {formatDate(purchase.active.effectiveAt)}. Hasta entonces puedes seguir operando las ubicaciones incluidas.
            </p>
          </div>
        </CardContent>
      </Card>
    )
  }

  if (purchase.active) {
    return (
      <Card className="border-primary/20 bg-primary/5 py-0 shadow-none">
        <CardContent className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex gap-3">
            <div className="flex size-9 shrink-0 items-center justify-center rounded-full bg-background">
              <Building2Icon className="size-4" />
            </div>

            <div>
              <h2 className="text-sm font-semibold">
                Sucursal adicional activa
              </h2>

              <p className="mt-1 text-sm text-muted-foreground">
                Tienes {purchase.active.quantity} cupo{purchase.active.quantity === 1 ? '' : 's'} adicional{purchase.active.quantity === 1 ? '' : 'es'}. Se cobrarán {formatPrice(purchase.active.amount, purchase.active.currency)} al mes, más impuestos aplicables.
              </p>

              {purchase.active.nextBillingAt ? (
                <p className="mt-1 text-sm text-muted-foreground">
                  Próximo cobro: {formatDate(purchase.active.nextBillingAt)}.
                </p>
              ) : null}
            </div>
          </div>

          {purchase.canCancel ? (
            <Button
              type="button"
              size="sm"
              variant="outline"
              onClick={onCancel}
            >
              Cancelar adicional
            </Button>
          ) : null}
        </CardContent>
      </Card>
    )
  }

  return (
    <Card className="border-primary/20 bg-primary/5 py-0 shadow-none">
      <CardContent className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex gap-3">
          <div className="flex size-9 shrink-0 items-center justify-center rounded-full bg-background">
            <Building2Icon className="size-4" />
          </div>

          <div>
            <h2 className="text-sm font-semibold">
              Agrega otra sucursal
            </h2>

            <p className="mt-1 text-sm text-muted-foreground">
              {purchase.canPurchase && purchase.price
                ? `Contrata una ubicación adicional por ${formatPrice(purchase.price.amount, purchase.price.currency)} al mes. Después del pago podrás registrar sus datos.`
                : 'Un administrador con permiso de facturación puede contratar el complemento para esta ubicación.'}
            </p>
          </div>
        </div>

        {purchase.canPurchase ? (
          <Button
            type="button"
            size="sm"
            onClick={onPurchase}
            disabled={purchasing}
          >
            <CreditCardIcon className="size-4" />
            Contratar sucursal
          </Button>
        ) : null}
      </CardContent>
    </Card>
  )
}

function formatPrice(
  amount: number,
  currency: string,
) {
  return new Intl.NumberFormat(
    'es-MX',
    {
      style: 'currency',
      currency: currency.toUpperCase(),
    },
  ).format(amount / 100)
}

function formatDate(value: string) {
  return new Intl.DateTimeFormat('es-MX', {
    dateStyle: 'long',
  }).format(new Date(value))
}

function BranchRow({
  branch,
  onEdit,
  onEnable,
  enabling,
  canEnable,
}: {
  branch: Branch
  onEdit: () => void
  onEnable: () => void
  enabling: boolean
  canEnable: boolean
}) {
  const street =
    branch.street
      ? [
        branch.street,
        branch.exteriorNumber,
        branch.interiorNumber
          ? `Int. ${branch.interiorNumber}`
          : null,
      ]
        .filter(Boolean)
        .join(' ')
      : null

  const address = [
    street,
    branch.neighborhood,
    branch.city,
    branch.postalCode
      ? `CP ${branch.postalCode}`
      : null,
  ]
    .filter(Boolean)
    .join(', ')

  return (
    <Card className="py-0 shadow-none transition-colors hover:bg-muted/20">
      <CardContent className="flex items-center gap-4 p-4">
        <div className="flex size-10 shrink-0 items-center justify-center rounded-lg border bg-muted/35">
          <Building2Icon className="size-4 text-muted-foreground" />
        </div>

        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="truncate text-sm font-medium">
              {branch.name}
            </h2>

            <Badge
              variant={
                branch.isActive
                  ? 'success'
                  : 'outline'
              }
            >
              {branch.isActive
                ? 'Activa'
                : 'Inactiva'}
            </Badge>

            {!branch.billingEnabled ? (
              <Badge variant="outline">
                No incluida en el plan
              </Badge>
            ) : null}
          </div>

          <div className="mt-1 flex min-w-0 items-start gap-1.5 text-sm text-muted-foreground">
            <MapPinIcon className="mt-0.5 size-3.5 shrink-0" />

            <span className="truncate">
              {address ||
                'Dirección pendiente'}
            </span>
          </div>
        </div>

        <div className="flex shrink-0 items-center gap-1">
          {!branch.billingEnabled && canEnable ? (
            <Button type="button" size="sm" variant="outline" onClick={onEnable} disabled={enabling}>
              <RotateCcwIcon className={enabling ? 'size-4 animate-spin' : 'size-4'} />
              <span className="hidden sm:inline">Incluir en el plan</span>
            </Button>
          ) : null}
          <Button
            type="button"
            size="sm"
            variant="ghost"
            onClick={onEdit}
          >
            <PencilIcon className="size-4" />
            <span className="hidden sm:inline">
              Editar
            </span>
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}

function EmptyBranches({
  onCreate,
  canCreate,
}: {
  onCreate: () => void
  canCreate: boolean
}) {
  return (
    <div className="rounded-xl border border-dashed px-6 py-14 text-center">
      <div className="mx-auto flex size-10 items-center justify-center rounded-lg border bg-muted/30">
        <Building2Icon className="size-4 text-muted-foreground" />
      </div>

      <h2 className="mt-4 text-sm font-medium">
        No hay sucursales registradas
      </h2>

      <p className="mx-auto mt-1 max-w-sm text-sm leading-6 text-muted-foreground">
        Agrega una ubicación para
        administrar sus datos operativos y
        fiscales.
      </p>

      {canCreate ? (
        <Button
          type="button"
          size="sm"
          className="mt-5"
          onClick={onCreate}
        >
          <PlusIcon className="size-4" />
          Agregar sucursal
        </Button>
      ) : null}
    </div>
  )
}

function BranchesSkeleton() {
  return (
    <div className="space-y-6">
      <div className="flex items-start justify-between gap-4">
        <div className="space-y-2">
          <Skeleton className="h-6 w-32" />
          <Skeleton className="h-4 w-80 max-w-full" />
        </div>

        <Skeleton className="h-9 w-36" />
      </div>

      <div className="space-y-2.5">
        <Skeleton className="h-[74px] w-full rounded-xl" />
        <Skeleton className="h-[74px] w-full rounded-xl" />
      </div>
    </div>
  )
}
