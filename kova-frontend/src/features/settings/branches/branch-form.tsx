import {
  useEffect,
  useState,
  type FormEvent,
} from 'react'
import {
  ArrowUpRightIcon,
  CheckCircle2Icon,
  Loader2Icon,
} from 'lucide-react'
import { Link } from '@tanstack/react-router'
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
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { useSession } from '@/features/auth/session-context'
import { getPostalCode } from '@/features/fiscal/sat-catalog-api'

import {
  branchApiErrors,
  getBranchFiscalSettings,
  updateBranchFiscalSettings,
} from './api'
import type {
  Branch,
  BranchPayload,
} from './types'

type FormValues = {
  code: string
  name: string
  postal_code: string
  city: string
  neighborhood: string
  street: string
  exterior_number: string
  interior_number: string
  phone: string
  is_active: boolean
}

const emptyForm: FormValues = {
  code: '',
  name: '',
  postal_code: '',
  city: '',
  neighborhood: '',
  street: '',
  exterior_number: '',
  interior_number: '',
  phone: '',
  is_active: true,
}

export function BranchForm({
  branch,
  allowsInvoicing,
  busy,
  errors,
  onCancel,
  onSubmit,
}: {
  branch: Branch | null
  allowsInvoicing: boolean
  busy: boolean
  errors: Record<string, string>
  onCancel: () => void
  onSubmit: (
    payload: BranchPayload,
  ) => void
}) {
  const { activeTenant } = useSession()
  const queryClient = useQueryClient()

  const canViewFiscal =
    allowsInvoicing &&
    (activeTenant?.abilities.includes(
      'fiscal.view'
    ) ?? false)

  const canManageFiscal =
    allowsInvoicing &&
    (activeTenant?.abilities.includes(
      'fiscal.manage'
    ) ?? false)

  const fiscalKey = [
    'branch-fiscal-settings',
    activeTenant?.id,
    branch?.id,
  ]

  const fiscalQuery = useQuery({
    queryKey: fiscalKey,
    queryFn: () =>
      getBranchFiscalSettings(
        branch!.id
      ),
    enabled:
      Boolean(branch?.id) &&
      canViewFiscal,
  })

  const [fiscalMode, setFiscalMode] =
    useState<'inherit' | 'override'>(
      'inherit'
    )

  const [fiscalSeries, setFiscalSeries] =
    useState('')

  useEffect(() => {
    if (!fiscalQuery.data) {
      return
    }

    setFiscalMode(
      fiscalQuery.data.mode
    )

    setFiscalSeries(
      fiscalQuery.data.effective_series
    )
  }, [fiscalQuery.data])

  const saveFiscal = useMutation({
    mutationFn: async () => {
      if (!branch) {
        throw new Error(
          'La sucursal todavía no existe.'
        )
      }

      if (fiscalMode === 'inherit') {
        return updateBranchFiscalSettings(
          branch.id,
          { mode: 'inherit' }
        )
      }

      if (!fiscalSeries) {
        throw new Error(
          'Selecciona una serie fiscal.'
        )
      }

      return updateBranchFiscalSettings(
        branch.id,
        {
          mode: 'override',
          series: fiscalSeries,
        }
      )
    },

    onSuccess: (data) => {
      queryClient.setQueryData(
        fiscalKey,
        data
      )

      setFiscalMode(data.mode)
      setFiscalSeries(
        data.effective_series
      )

      toast.success(
        'Serie fiscal actualizada.'
      )
    },

    onError: (error) => {
      const message =
        error instanceof Error &&
          !('response' in error)
          ? error.message
          : branchApiErrors(error).form

      toast.error(
        message ??
        'No pudimos guardar la serie fiscal.'
      )
    },
  })

  const [form, setForm] =
    useState<FormValues>(
      () => valuesFrom(branch),
    )

  const [
    debouncedPostalCode,
    setDebouncedPostalCode,
  ] = useState(
    form.postal_code,
  )

  useEffect(() => {
    const timeout =
      window.setTimeout(() => {
        setDebouncedPostalCode(
          form.postal_code,
        )
      }, 400)

    return () =>
      window.clearTimeout(timeout)
  }, [form.postal_code])

  const postalQuery = useQuery({
    queryKey: [
      'sat-postal-code',
      debouncedPostalCode,
    ],

    queryFn: () =>
      getPostalCode(
        debouncedPostalCode,
      ),

    enabled:
      /^\d{5}$/.test(
        debouncedPostalCode,
      ),

    retry: false,
    staleTime: 30 * 60 * 1000,
  })

  const postal =
    postalQuery.data?.data.code
      === form.postal_code
      ? postalQuery.data.data
      : null

  const selectedFiscalSeries = fiscalQuery.data?.series.find(
    (item) => item.series === (
      fiscalMode === 'inherit'
        ? fiscalQuery.data.default_series
        : fiscalSeries
    ),
  )

  function change<
    K extends keyof FormValues,
  >(
    field: K,
    value: FormValues[K],
  ) {
    setForm((current) => ({
      ...current,
      [field]: value,
    }))
  }

  function submit(
    event: FormEvent<HTMLFormElement>,
  ) {
    event.preventDefault()

    if (!postal) {
      return
    }

    onSubmit({
      code: form.code.trim(),

      name:
        form.name.trim(),

      postal_code:
        form.postal_code,

      city:
        form.city.trim(),

      neighborhood:
        form.neighborhood.trim(),

      street:
        form.street.trim(),

      exterior_number:
        form.exterior_number.trim(),

      interior_number:
        form.interior_number.trim()
        || null,

      phone:
        form.phone.trim()
        || null,

      is_active:
        form.is_active,
    })
  }

  return (
    <form
      onSubmit={submit}
      className="flex min-h-0 flex-1 flex-col"
    >
      <div className="flex-1 overflow-y-auto px-8 py-7">
        <div className="space-y-9">
          {errors.form ? (
            <Alert variant="destructive">
              <AlertDescription>
                {errors.form}
              </AlertDescription>
            </Alert>
          ) : null}

          <FormSection
            title="Información general"
            description="Identifica esta sucursal dentro de Kova."
          >
            <FormField
              label="Nombre de sucursal"
              error={errors.name}
            >
              <Input
                value={form.name}
                placeholder="Sucursal Centro"
                maxLength={120}
                required
                autoFocus
                onChange={(event) =>
                  change(
                    'name',
                    event.target.value,
                  )
                }
                aria-invalid={
                  Boolean(errors.name)
                }
              />
            </FormField>

            <FormField
              label="Código interno"
              description="Clave corta para identificarla en operación y reportes. No se muestra al cliente."
              error={errors.code}
            >
              <Input
                value={form.code}
                placeholder="QRO01"
                maxLength={20}
                required
                autoComplete="off"
                onChange={(event) =>
                  change(
                    'code',
                    event.target.value
                      .toUpperCase(),
                  )
                }
                aria-invalid={
                  Boolean(errors.code)
                }
              />
            </FormField>
          </FormSection>

          <FormSection
            title="Dirección"
            description="Kova valida automáticamente el código postal con el catálogo SAT."
          >
            <FormField
              label="Código postal"
              error={
                errors.postal_code
                ?? postalError(
                  postalQuery.isError,
                  form.postal_code,
                )
              }
            >
              <div className="space-y-1.5">
                <div className="relative">
                  <Input
                    value={
                      form.postal_code
                    }
                    inputMode="numeric"
                    autoComplete="postal-code"
                    placeholder="00000"
                    maxLength={5}
                    required
                    className="pr-9"
                    onChange={(event) =>
                      change(
                        'postal_code',
                        event.target.value
                          .replace(
                            /\D/g,
                            '',
                          )
                          .slice(0, 5),
                      )
                    }
                    aria-invalid={
                      Boolean(
                        errors.postal_code
                        || postalQuery.isError,
                      )
                    }
                  />

                  {postalQuery.isFetching ? (
                    <Loader2Icon className="absolute right-3 top-1/2 size-4 -translate-y-1/2 animate-spin text-muted-foreground" />
                  ) : postal ? (
                    <CheckCircle2Icon className="absolute right-3 top-1/2 size-4 -translate-y-1/2 text-emerald-600" />
                  ) : null}
                </div>

                {postal ? (
                  <p className="flex items-center gap-1.5 text-xs text-emerald-700 dark:text-emerald-500">
                    <CheckCircle2Icon className="size-3.5" />
                    Código postal validado
                  </p>
                ) : null}
              </div>
            </FormField>

            <div className="grid gap-4 sm:grid-cols-2">
              <FormField
                label="Ciudad"
                error={errors.city}
              >
                <Input
                  value={form.city}
                  placeholder="Querétaro"
                  required
                  maxLength={180}
                  onChange={(event) =>
                    change(
                      'city',
                      event.target.value,
                    )
                  }
                />
              </FormField>

              <FormField
                label="Colonia"
                error={
                  errors.neighborhood
                }
              >
                <Input
                  value={
                    form.neighborhood
                  }
                  placeholder="Centro"
                  required
                  maxLength={180}
                  onChange={(event) =>
                    change(
                      'neighborhood',
                      event.target.value,
                    )
                  }
                />
              </FormField>
            </div>

            <FormField
              label="Calle"
              error={errors.street}
            >
              <Input
                value={form.street}
                placeholder="Nombre de la calle"
                required
                maxLength={180}
                onChange={(event) =>
                  change(
                    'street',
                    event.target.value,
                  )
                }
              />
            </FormField>

            <div className="grid gap-4 sm:grid-cols-2">
              <FormField
                label="Número exterior"
                error={
                  errors.exterior_number
                }
              >
                <Input
                  value={
                    form.exterior_number
                  }
                  placeholder="100"
                  required
                  maxLength={30}
                  onChange={(event) =>
                    change(
                      'exterior_number',
                      event.target.value,
                    )
                  }
                />
              </FormField>

              <FormField
                label="Número interior"
                error={
                  errors.interior_number
                }
                optional
              >
                <Input
                  value={
                    form.interior_number
                  }
                  placeholder="3B"
                  maxLength={30}
                  onChange={(event) =>
                    change(
                      'interior_number',
                      event.target.value,
                    )
                  }
                />
              </FormField>
            </div>
          </FormSection>

          <FormSection
            title="Contacto"
          >
            <FormField
              label="Teléfono"
              error={errors.phone}
              optional
            >
              <Input
                value={form.phone}
                type="tel"
                placeholder="442 000 0000"
                maxLength={30}
                autoComplete="tel"
                onChange={(event) =>
                  change(
                    'phone',
                    event.target.value,
                  )
                }
              />
            </FormField>
          </FormSection>

          {branch && canViewFiscal ? (
            <FormSection
              title="Serie de facturación"
              description="Elige si esta sucursal comparte la numeración general del negocio o lleva un consecutivo propio."
            >
              {fiscalQuery.isPending ? (
                <div className="rounded-lg border bg-muted/20 px-4 py-3 text-sm text-muted-foreground">
                  Cargando configuración fiscal...
                </div>
              ) : fiscalQuery.isError ? (
                <Alert variant="destructive">
                  <AlertDescription>
                    No pudimos cargar la serie fiscal de esta sucursal.
                  </AlertDescription>
                </Alert>
              ) : fiscalQuery.data ? (
                <div className="space-y-4">
                  <FormField
                    label="Numeración"
                  >
                    <Select
                      value={fiscalMode}
                      disabled={
                        !canManageFiscal ||
                        saveFiscal.isPending
                      }
                      onValueChange={(value) => {
                        const mode =
                          value as
                          | 'inherit'
                          | 'override'

                        setFiscalMode(mode)

                        if (
                          mode === 'override' &&
                          !fiscalSeries
                        ) {
                          setFiscalSeries(
                            fiscalQuery.data
                              .effective_series
                          )
                        }
                      }}
                    >
                      <SelectTrigger className="w-full">
                        <SelectValue />
                      </SelectTrigger>

                      <SelectContent>
                        <SelectItem value="inherit">
                          Usar la serie del negocio · {fiscalQuery.data.default_series}
                        </SelectItem>

                        <SelectItem value="override">
                          Elegir otra serie
                        </SelectItem>
                      </SelectContent>
                    </Select>
                  </FormField>

                  {fiscalMode === 'override' ? (
                    <FormField
                      label="Serie asignada"
                    >
                      <Select
                        value={fiscalSeries}
                        disabled={
                          !canManageFiscal ||
                          saveFiscal.isPending
                        }
                        onValueChange={
                          setFiscalSeries
                        }
                      >
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder="Selecciona una serie" />
                        </SelectTrigger>

                        <SelectContent>
                          {fiscalQuery.data.series.map(
                            (item) => (
                              <SelectItem
                                key={item.series}
                                value={item.series}
                              >
                                {item.series} · siguiente {item.next_number}
                              </SelectItem>
                            )
                          )}
                        </SelectContent>
                      </Select>
                    </FormField>
                  ) : null}

                  {canManageFiscal ? (
                    <div className="flex flex-col gap-1 rounded-lg bg-muted/35 px-3.5 py-3 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
                      <span>
                        ¿Necesitas una serie diferente?
                      </span>

                      <Link
                        to="/$tenantSlug/settings/fiscal"
                        params={{ tenantSlug: activeTenant!.slug }}
                        className="inline-flex w-fit items-center gap-1 font-medium text-foreground underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                      >
                        Crear o administrar series
                        <ArrowUpRightIcon className="size-3.5" />
                      </Link>
                    </div>
                  ) : null}

                  <div className="flex flex-col gap-3 rounded-lg border bg-muted/15 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                      <p className="text-xs text-muted-foreground">
                        Próximo CFDI
                      </p>

                      <p className="mt-1 text-sm font-medium">
                        {selectedFiscalSeries?.next_number
                          ?? fiscalQuery.data.effective_next_number}
                      </p>

                      <p className="mt-1 text-xs text-muted-foreground">
                        Serie{' '}
                        {fiscalMode === 'inherit'
                          ? fiscalQuery.data.default_series
                          : fiscalSeries}
                      </p>
                    </div>

                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      disabled={
                        !canManageFiscal ||
                        saveFiscal.isPending ||
                        (
                          fiscalMode === 'override' &&
                          !fiscalSeries
                        )
                      }
                      onClick={() =>
                        saveFiscal.mutate()
                      }
                    >
                      {saveFiscal.isPending
                        ? 'Guardando...'
                        : 'Guardar numeración'}
                    </Button>
                  </div>
                </div>
              ) : null}
            </FormSection>
          ) : null}

          <div className="flex items-center justify-between gap-5 rounded-lg border px-4 py-3.5">
            <div>
              <Label
                htmlFor="branch-active"
                className="text-sm font-medium"
              >
                Sucursal activa
              </Label>

              <p className="mt-1 text-xs leading-5 text-muted-foreground">
                Permite utilizar esta
                ubicación en la operación
                diaria.
              </p>
            </div>

            <Switch
              id="branch-active"
              checked={form.is_active}
              onCheckedChange={(
                checked,
              ) =>
                change(
                  'is_active',
                  checked,
                )
              }
            />
          </div>
        </div>
      </div>

      <div className="flex shrink-0 justify-end gap-2 border-t bg-background px-6 py-4">
        <Button
          type="button"
          variant="outline"
          onClick={onCancel}
          disabled={busy}
        >
          Cancelar
        </Button>

        <Button
          type="submit"
          disabled={
            busy
            || !postal
          }
        >
          {busy ? (
            <Loader2Icon className="size-4 animate-spin" />
          ) : null}

          {branch
            ? 'Guardar cambios'
            : 'Crear sucursal'}
        </Button>
      </div>
    </form>
  )
}

function FormSection({
  title,
  description,
  children,
}: {
  title: string
  description?: string
  children: React.ReactNode
}) {
  return (
    <section className="space-y-4">
      <div className="space-y-1">
        <h3 className="text-sm font-medium">
          {title}
        </h3>

        {description ? (
          <p className="text-xs leading-5 text-muted-foreground">
            {description}
          </p>
        ) : null}
      </div>

      <div className="space-y-4">
        {children}
      </div>
    </section>
  )
}

function FormField({
  label,
  description,
  error,
  optional,
  children,
}: {
  label: string
  description?: string
  error?: string
  optional?: boolean
  children: React.ReactNode
}) {
  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between gap-3">
        <Label>
          {label}
        </Label>

        {optional ? (
          <span className="text-xs text-muted-foreground">
            Opcional
          </span>
        ) : null}
      </div>

      {description ? (
        <p className="text-xs leading-5 text-muted-foreground">
          {description}
        </p>
      ) : null}

      {children}

      {error ? (
        <p
          className="text-xs text-destructive"
          role="alert"
        >
          {error}
        </p>
      ) : null}
    </div>
  )
}

function postalError(
  isError: boolean,
  value: string,
): string | undefined {
  if (
    value.length > 0
    && value.length < 5
  ) {
    return 'Ingresa los 5 dígitos del código postal.'
  }

  return isError
    ? 'No encontramos este código postal en el catálogo SAT vigente.'
    : undefined
}

function valuesFrom(
  branch: Branch | null,
): FormValues {
  if (!branch) {
    return emptyForm
  }

  return {
    code:
      branch.code,

    name:
      branch.name,

    postal_code:
      branch.postalCode ?? '',

    city:
      branch.city ?? '',

    neighborhood:
      branch.neighborhood ?? '',

    street:
      branch.street ?? '',

    exterior_number:
      branch.exteriorNumber ?? '',

    interior_number:
      branch.interiorNumber ?? '',

    phone:
      branch.phone ?? '',

    is_active:
      branch.isActive,
  }
} 
