import {
  type FormEvent,
  useEffect,
  useMemo,
  useState,
} from 'react'
import {
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import {
  AlertCircleIcon,
} from 'lucide-react'

import {
  Alert,
  AlertDescription,
} from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button, buttonVariants } from '@/components/ui/button'
import { CatalogCombobox } from '@/components/ui/catalog-combobox'
import {
  Field as UiField,
  FieldDescription,
  FieldError,
  FieldLabel,
} from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'

import { useSession } from '@/features/auth/session-context'
import {
  getPostalCode,
  getTaxObjects,
  getTaxRegimes,
  searchProductServices,
  searchUnits,
} from '@/features/fiscal/sat-catalog-api'
import {
  createFiscalSeries,
  getFiscalProfile,
  getFiscalSeries,
  setDefaultFiscalSeries,
  updateFiscalProfile,
  uploadCsd,
  type FiscalProfile,
  type FiscalProfileInput,
} from './api'

type CatalogOption = {
  code: string
  description: string
}

type FormErrors = Partial<
  Record<keyof FiscalProfileInput, string>
>

type PostalState =
  | 'idle'
  | 'checking'
  | 'valid'
  | 'invalid'

const defaults: FiscalProfileInput = {
  rfc: '',
  legal_name: '',
  tax_regime_code: '',
  postal_code: '',
  fiscal_email: '',
  default_product_service_code: '',
  default_unit_code: '',
  default_tax_object_code: '',
}

export function FiscalSettingsPage() {
  const { activeTenant } = useSession()
  const queryClient = useQueryClient()

  const profileKey = [
    'fiscal-profile',
    activeTenant?.id,
  ]

  const profileQuery = useQuery({
    queryKey: profileKey,
    queryFn: getFiscalProfile,
    enabled: Boolean(activeTenant?.id),
  })

  const seriesKey = [
    'fiscal-series',
    activeTenant?.id,
  ]

  const seriesQuery = useQuery({
    queryKey: seriesKey,
    queryFn: getFiscalSeries,
    enabled: Boolean(activeTenant?.id),
  })

  const [form, setForm] =
    useState<FiscalProfileInput>(defaults)

  const [formErrors, setFormErrors] =
    useState<FormErrors>({})

  const [postalState, setPostalState] =
    useState<PostalState>('idle')

  const [replacingCsd, setReplacingCsd] =
    useState(false)

  const [validatingForm, setValidatingForm] =
    useState(false)

  const [newSeries, setNewSeries] =
    useState('')

  const [seriesError, setSeriesError] =
    useState<string | null>(null)

  useEffect(() => {
    if (!profileQuery.data) {
      return
    }

    setForm(
      fromProfile(profileQuery.data)
    )

    setFormErrors({})

    setPostalState(
      profileQuery.data.postal_code &&
        profileQuery.data.configuration_valid
        ? 'valid'
        : 'idle'
    )
  }, [profileQuery.data])

  const normalizedRfc =
    form.rfc.trim().toUpperCase()

  const hasValidRfcLength =
    normalizedRfc.length === 12 ||
    normalizedRfc.length === 13

  /*
   * Catálogo de regímenes.
   */
  const regimesQuery = useQuery({
    queryKey: [
      'sat-regimes',
      normalizedRfc,
    ],
    queryFn: () =>
      getTaxRegimes(normalizedRfc),
    enabled: hasValidRfcLength,
    retry: false,
  })

  /*
   * Catálogo de objetos de impuesto.
   */
  const taxObjectsQuery = useQuery({
    queryKey: ['sat-tax-objects'],
    queryFn: getTaxObjects,
    retry: false,
  })

  /*
   * Combina lo regresado por catálogo con el valor
   * actual del perfil.
   *
   * Así el Select puede mostrar un valor guardado
   * incluso antes de que termine de cargar el catálogo.
   */
  const regimeOptions = useMemo(
    () =>
      mergeCatalogOptions(
        regimesQuery.data?.data,
        profileQuery.data?.tax_regime
      ),
    [
      regimesQuery.data,
      profileQuery.data?.tax_regime,
    ]
  )

  const taxObjectOptions = useMemo(
    () =>
      mergeCatalogOptions(
        taxObjectsQuery.data?.data,
        profileQuery.data?.default_tax_object
      ),
    [
      taxObjectsQuery.data,
      profileQuery.data?.default_tax_object,
    ]
  )

  /*
   * Valores visuales seleccionados.
   *
   * No dejamos que Radix/shadcn tenga que
   * deducirlos por su cuenta.
   */
  const selectedRegime = useMemo(
    () =>
      findCatalogOption(
        regimeOptions,
        form.tax_regime_code
      ),
    [
      regimeOptions,
      form.tax_regime_code,
    ]
  )

  const selectedTaxObject = useMemo(
    () =>
      findCatalogOption(
        taxObjectOptions,
        form.default_tax_object_code
      ),
    [
      taxObjectOptions,
      form.default_tax_object_code,
    ]
  )

  const saveProfile = useMutation({
    mutationFn: updateFiscalProfile,

    onSuccess: (data) => {
      queryClient.setQueryData(
        profileKey,
        data
      )

      setFormErrors({})
    },

    onError: (error) => {
      const errors =
        validationErrorsOf(error)

      if (
        Object.keys(errors).length
      ) {
        setFormErrors(
          (current) => ({
            ...current,
            ...errors,
          })
        )
      }
    },
  })

  const uploadCertificate =
    useMutation({
      mutationFn: uploadCsd,

      onSuccess: (data) => {
        queryClient.setQueryData(
          profileKey,
          data
        )

        setReplacingCsd(false)
      },
    })

  const createSeries = useMutation({
    mutationFn: createFiscalSeries,

    onSuccess: async (data) => {
      queryClient.setQueryData(
        seriesKey,
        data
      )

      setNewSeries('')

      await queryClient.invalidateQueries({
        queryKey: profileKey,
      })
    },
  })

  const setDefaultSeries = useMutation({
    mutationFn: setDefaultFiscalSeries,

    onSuccess: async (data) => {
      queryClient.setQueryData(
        seriesKey,
        data
      )

      await queryClient.invalidateQueries({
        queryKey: profileKey,
      })
    },
  })

  const canManage =
    activeTenant?.abilities.includes(
      'fiscal.manage'
    ) ?? false

  const hasRequiredProfileData =
    Boolean(
      form.rfc.trim() &&
      form.legal_name.trim() &&
      form.tax_regime_code.trim() &&
      form.postal_code.trim() &&
      form.fiscal_email.trim() &&
      form.default_product_service_code.trim() &&
      form.default_unit_code.trim() &&
      form.default_tax_object_code.trim()
    )

  const normalizedNewSeries =
    newSeries
      .trim()
      .toUpperCase()

  const newSeriesIsValid =
    /^[A-Z0-9]{1,25}$/.test(
      normalizedNewSeries
    )

  const newSeriesAlreadyExists =
    seriesQuery.data?.series.some(
      (item) =>
        item.series.toUpperCase() ===
        normalizedNewSeries
    ) ?? false

  const canSaveProfile =
    canManage &&
    hasRequiredProfileData &&
    !saveProfile.isPending &&
    !validatingForm

  const canCreateSeries =
    canManage &&
    newSeriesIsValid &&
    !newSeriesAlreadyExists &&
    !createSeries.isPending

  const requestError = errorOf(
    saveProfile.error ??
    uploadCertificate.error ??
    createSeries.error ??
    setDefaultSeries.error
  )

  function clearError(
    field: keyof FiscalProfileInput
  ) {
    setFormErrors(
      (current) => {
        if (!current[field]) {
          return current
        }

        const next = {
          ...current,
        }

        delete next[field]

        return next
      }
    )
  }

  function updateField<
    K extends keyof FiscalProfileInput,
  >(
    field: K,
    value: FiscalProfileInput[K]
  ) {
    clearError(field)

    setForm(
      (current) => ({
        ...current,
        [field]: value,
      })
    )
  }

  async function validatePostal(): Promise<boolean> {
    const postalCode =
      form.postal_code.trim()

    if (
      !/^\d{5}$/.test(postalCode)
    ) {
      setPostalState('invalid')

      setFormErrors(
        (current) => ({
          ...current,
          postal_code:
            'Ingresa un código postal fiscal de 5 dígitos.',
        })
      )

      return false
    }

    setPostalState('checking')

    try {
      await getPostalCode(
        postalCode
      )

      setPostalState('valid')

      clearError('postal_code')

      return true
    } catch {
      setPostalState('invalid')

      setFormErrors(
        (current) => ({
          ...current,
          postal_code:
            'El código postal no corresponde a un código vigente del SAT.',
        })
      )

      return false
    }
  }

  async function validateForm(): Promise<boolean> {
    const errors: FormErrors = {}

    const rfc =
      form.rfc.trim().toUpperCase()

    const legalName =
      form.legal_name.trim()

    const email =
      form.fiscal_email.trim()

    const regime =
      form.tax_regime_code.trim()

    const productCode =
      form.default_product_service_code.trim()

    const unitCode =
      form.default_unit_code.trim()

    const taxObject =
      form.default_tax_object_code.trim()

    if (
      !/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/.test(
        rfc
      )
    ) {
      errors.rfc =
        'Ingresa un RFC válido.'
    }

    if (!legalName) {
      errors.legal_name =
        'Ingresa el nombre o razón social.'
    }

    if (!regime) {
      errors.tax_regime_code =
        'Selecciona tu régimen fiscal.'
    } else if (
      regimeOptions.length > 0 &&
      !regimeOptions.some(
        (entry) =>
          entry.code === regime
      )
    ) {
      errors.tax_regime_code =
        'El régimen fiscal seleccionado no es válido para este RFC.'
    }

    if (
      !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(
        email
      )
    ) {
      errors.fiscal_email =
        'Ingresa un correo electrónico válido.'
    }

    if (
      !/^\d{8}$/.test(
        productCode
      )
    ) {
      errors.default_product_service_code =
        'Selecciona un producto o servicio SAT válido.'
    }

    if (!unitCode) {
      errors.default_unit_code =
        'Selecciona una unidad SAT.'
    }

    if (!taxObject) {
      errors.default_tax_object_code =
        'Selecciona el objeto de impuesto.'
    } else if (
      taxObjectOptions.length > 0 &&
      !taxObjectOptions.some(
        (entry) =>
          entry.code === taxObject
      )
    ) {
      errors.default_tax_object_code =
        'El objeto de impuesto seleccionado no es válido.'
    }

    setFormErrors(errors)

    if (
      Object.keys(errors).length > 0
    ) {
      return false
    }

    return validatePostal()
  }

  async function submitProfile(
    event: FormEvent<HTMLFormElement>
  ) {
    event.preventDefault()

    if (
      !canManage ||
      saveProfile.isPending ||
      validatingForm
    ) {
      return
    }

    /*
     * Si faltan datos obligatorios no hacemos
     * ninguna llamada al endpoint. Ejecutamos la
     * validación local únicamente para mostrar los
     * errores correspondientes en el formulario.
     */
    if (!hasRequiredProfileData) {
      await validateForm()

      return
    }

    setValidatingForm(true)

    try {
      const valid =
        await validateForm()

      if (!valid) {
        return
      }

      await saveProfile.mutateAsync(
        normalizeForm(form)
      )
    } finally {
      setValidatingForm(false)
    }
  }

  function handleSetDefaultSeries(
    series: string
  ) {
    if (
      !canManage ||
      setDefaultSeries.isPending
    ) {
      return
    }

    const normalized =
      series
        .trim()
        .toUpperCase()

    if (!normalized) {
      return
    }

    const current =
      seriesQuery.data?.series.find(
        (item) =>
          item.series.toUpperCase() ===
          normalized
      )

    if (
      !current ||
      current.is_default
    ) {
      return
    }

    setDefaultSeries.mutate(
      current.series
    )
  }

  function submitSeries(
    event: FormEvent<HTMLFormElement>
  ) {
    event.preventDefault()

    setSeriesError(null)

    if (
      !canManage ||
      createSeries.isPending
    ) {
      return
    }

    if (!normalizedNewSeries) {
      setSeriesError(
        'Captura una serie.'
      )

      return
    }

    if (!newSeriesIsValid) {
      setSeriesError(
        'La serie sólo puede contener letras y números, con un máximo de 25 caracteres.'
      )

      return
    }

    if (newSeriesAlreadyExists) {
      setSeriesError(
        'Esta serie ya existe.'
      )

      return
    }

    createSeries.mutate(
      normalizedNewSeries
    )
  }

  if (profileQuery.isPending) {
    return (
      <div className="w-full space-y-5">
        <Skeleton className="h-16 rounded-xl" />
        <Skeleton className="h-80 rounded-xl" />
        <Skeleton className="h-72 rounded-xl" />
      </div>
    )
  }

  return (
    <div className="min-w-0 w-full max-w-6xl space-y-5 pb-10">
      <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">
            Facturación fiscal
          </h1>

          <p className="mt-1 max-w-2xl text-sm leading-6 text-muted-foreground">
            Configura los datos fiscales de tu negocio y el
            Certificado de Sello Digital necesario para emitir CFDI.
          </p>
        </div>

        <Status
          profile={profileQuery.data}
        />
      </header>

      {profileQuery.isError && (
        <Alert variant="destructive">
          <AlertCircleIcon />

          <AlertDescription>
            No pudimos cargar la configuración fiscal.
          </AlertDescription>
        </Alert>
      )}

      {requestError && (
        <Alert variant="destructive">
          <AlertCircleIcon />

          <AlertDescription>
            {requestError}
          </AlertDescription>
        </Alert>
      )}

      {profileQuery.data
        ?.validation_issues
        ?.length ? (
        <Alert>
          <AlertCircleIcon />

          <AlertDescription>
            <strong>
              Hay información fiscal que requiere atención.
            </strong>

            <ul className="mt-2 list-disc space-y-1 pl-4">
              {profileQuery.data
                .validation_issues
                .slice(0, 5)
                .map(
                  (
                    issue,
                    index
                  ) => (
                    <li
                      key={`${issue.scope}-${issue.resource_id ?? ''}-${issue.field}-${index}`}
                    >
                      {issue.message}
                    </li>
                  )
                )}
            </ul>
          </AlertDescription>
        </Alert>
      ) : null}

      <div className="space-y-5">
        <form
          className="overflow-hidden rounded-xl bg-card ring-1 ring-foreground/10"
          onSubmit={submitProfile}
        >
        <Section
          title="Datos del emisor"
          description="Captura los datos tal como aparecen en tu Constancia de Situación Fiscal."
        >
          <div className="grid gap-x-5 gap-y-5 md:grid-cols-2">
            <FormField
              label="RFC"
              description={
                formErrors.rfc ??
                'RFC de la persona física o moral que emitirá los CFDI.'
              }
              tone={
                formErrors.rfc
                  ? 'destructive'
                  : 'muted'
              }
            >
              <Input
                required
                value={form.rfc}
                maxLength={13}
                placeholder="Ej. ABC010203AB1"
                className="h-10"
                aria-invalid={
                  Boolean(
                    formErrors.rfc
                  )
                }
                onChange={(
                  event
                ) => {
                  const rfc =
                    event.target.value
                      .toUpperCase()
                      .replace(
                        /\s/g,
                        ''
                      )

                  clearError('rfc')
                  clearError(
                    'tax_regime_code'
                  )

                  setForm(
                    (current) => ({
                      ...current,
                      rfc,
                      tax_regime_code:
                        '',
                    })
                  )
                }}
              />
            </FormField>

            <FormField
              label="Nombre o razón social"
              description={
                formErrors.legal_name ??
                'Debe coincidir exactamente con el nombre registrado ante el SAT.'
              }
              tone={
                formErrors.legal_name
                  ? 'destructive'
                  : 'muted'
              }
            >
              <Input
                required
                value={
                  form.legal_name
                }
                placeholder="Ej. CAFETERÍA EJEMPLO SA DE CV"
                autoComplete="organization"
                className="h-10"
                aria-invalid={
                  Boolean(
                    formErrors.legal_name
                  )
                }
                onChange={(
                  event
                ) =>
                  updateField(
                    'legal_name',
                    event.target.value
                  )
                }
              />
            </FormField>

            <FormField
              label="Régimen fiscal"
              description={
                formErrors.tax_regime_code ??
                (
                  hasValidRfcLength
                    ? 'Selecciona el régimen fiscal correspondiente a tu RFC.'
                    : 'Captura primero un RFC válido para consultar los regímenes disponibles.'
                )
              }
              tone={
                formErrors.tax_regime_code
                  ? 'destructive'
                  : 'muted'
              }
            >
              <Select
                key={
                  form.tax_regime_code ||
                  'empty-regime'
                }
                value={
                  form.tax_regime_code ||
                  undefined
                }
                disabled={
                  !hasValidRfcLength ||
                  (
                    regimesQuery.isPending &&
                    regimeOptions.length ===
                    0
                  )
                }
                onValueChange={(
                  value
                ) =>
                  updateField(
                    'tax_regime_code',
                    value
                  )
                }
              >
                <SelectTrigger
                  className="h-10 w-full"
                  aria-invalid={
                    Boolean(
                      formErrors.tax_regime_code
                    )
                  }
                >
                  <SelectValue
                    placeholder={
                      regimesQuery.isPending
                        ? 'Consultando regímenes...'
                        : 'Selecciona tu régimen fiscal'
                    }
                  >
                    {selectedRegime
                      ? `${selectedRegime.code} · ${selectedRegime.description}`
                      : undefined}
                  </SelectValue>
                </SelectTrigger>

                <SelectContent>
                  {regimeOptions.map(
                    (entry) => (
                      <SelectItem
                        key={
                          entry.code
                        }
                        value={
                          entry.code
                        }
                      >
                        {entry.code} ·{' '}
                        {
                          entry.description
                        }
                      </SelectItem>
                    )
                  )}
                </SelectContent>
              </Select>
            </FormField>

            <FormField
              label="Código postal fiscal"
              description={
                formErrors.postal_code ??
                postalDescription(
                  postalState
                )
              }
              tone={
                formErrors.postal_code ||
                  postalState ===
                  'invalid'
                  ? 'destructive'
                  : postalState ===
                    'valid'
                    ? 'success'
                    : 'muted'
              }
            >
              <Input
                required
                inputMode="numeric"
                value={
                  form.postal_code
                }
                maxLength={5}
                placeholder="Ej. 76000"
                className="h-10"
                aria-invalid={
                  Boolean(
                    formErrors.postal_code
                  )
                }
                onBlur={() => {
                  void validatePostal()
                }}
                onChange={(
                  event
                ) => {
                  setPostalState(
                    'idle'
                  )

                  updateField(
                    'postal_code',
                    event.target.value.replace(
                      /\D/g,
                      ''
                    )
                  )
                }}
              />
            </FormField>

            <FormField
              label="Correo para notificaciones"
              description={
                formErrors.fiscal_email ??
                'Aquí podrás recibir avisos relacionados con la facturación.'
              }
              tone={
                formErrors.fiscal_email
                  ? 'destructive'
                  : 'muted'
              }
            >
              <Input
                required
                type="email"
                value={
                  form.fiscal_email
                }
                placeholder="Ej. facturacion@tunegocio.com"
                autoComplete="email"
                className="h-10"
                aria-invalid={
                  Boolean(
                    formErrors.fiscal_email
                  )
                }
                onChange={(
                  event
                ) =>
                  updateField(
                    'fiscal_email',
                    event.target.value
                  )
                }
              />
            </FormField>
          </div>
        </Section>

        <Section
          title="Valores predeterminados"
          description="Se utilizarán únicamente cuando un producto no tenga su propia configuración fiscal."
        >
          <div className="grid gap-x-5 gap-y-5 md:grid-cols-2">
            <FormField
              label="Producto o servicio SAT"
              description={
                formErrors.default_product_service_code ??
                'Busca por clave SAT o por una descripción relacionada con tu actividad.'
              }
              tone={
                formErrors.default_product_service_code
                  ? 'destructive'
                  : 'muted'
              }
            >
              <CatalogCombobox
                value={
                  form.default_product_service_code
                }
                label={
                  catalogLabel(
                    profileQuery.data
                      ?.default_product_service,
                    form.default_product_service_code
                  )
                }
                placeholder="Ej. cafetería, alimentos o clave SAT..."
                search={
                  searchProductServices
                }
                onValueChange={(
                  code
                ) =>
                  updateField(
                    'default_product_service_code',
                    code
                  )
                }
              />
            </FormField>

            <FormField
              label="Unidad SAT"
              description={
                formErrors.default_unit_code ??
                'Selecciona la unidad que se utilizará por defecto en tus productos.'
              }
              tone={
                formErrors.default_unit_code
                  ? 'destructive'
                  : 'muted'
              }
            >
              <CatalogCombobox
                value={
                  form.default_unit_code
                }
                label={
                  catalogLabel(
                    profileQuery.data
                      ?.default_unit,
                    form.default_unit_code
                  )
                }
                placeholder="Ej. E48, H87 o Pieza..."
                search={searchUnits}
                onValueChange={(
                  code
                ) =>
                  updateField(
                    'default_unit_code',
                    code
                  )
                }
              />
            </FormField>

            <FormField
              label="Objeto de impuesto"
              description={
                formErrors.default_tax_object_code ??
                'Selecciona el tratamiento fiscal predeterminado para tus productos.'
              }
              tone={
                formErrors.default_tax_object_code
                  ? 'destructive'
                  : 'muted'
              }
            >
              <Select
                key={
                  form.default_tax_object_code ||
                  'empty-tax-object'
                }
                value={
                  form.default_tax_object_code ||
                  undefined
                }
                disabled={
                  taxObjectsQuery.isPending &&
                  taxObjectOptions.length ===
                  0
                }
                onValueChange={(
                  value
                ) =>
                  updateField(
                    'default_tax_object_code',
                    value
                  )
                }
              >
                <SelectTrigger
                  className="h-10 w-full"
                  aria-invalid={
                    Boolean(
                      formErrors.default_tax_object_code
                    )
                  }
                >
                  <SelectValue placeholder="Selecciona el objeto de impuesto">
                    {selectedTaxObject
                      ? `${selectedTaxObject.code} · ${selectedTaxObject.description}`
                      : undefined}
                  </SelectValue>
                </SelectTrigger>

                <SelectContent>
                  {taxObjectOptions.map(
                    (entry) => (
                      <SelectItem
                        key={
                          entry.code
                        }
                        value={
                          entry.code
                        }
                      >
                        {entry.code} ·{' '}
                        {
                          entry.description
                        }
                      </SelectItem>
                    )
                  )}
                </SelectContent>
              </Select>
            </FormField>
          </div>

          <div className="mt-6 flex justify-end border-t pt-4">
            <Button
              type="submit"
              size="sm"
              className="h-10 shrink-0"
              disabled={!canSaveProfile}
            >
              {saveProfile.isPending
                ? 'Guardando...'
                : validatingForm
                  ? 'Validando...'
                  : 'Guardar configuración'}
            </Button>
          </div>
        </Section>
        </form>

        <div className="overflow-hidden rounded-xl bg-card ring-1 ring-foreground/10">
          <Section
            title="Series y folios CFDI"
            description="Administra la numeración interna de tus facturas. Cada serie conserva su propio consecutivo dentro de este negocio."
            aside={
              seriesQuery.data ? (
                <Badge
                  variant="outline"
                  className="font-normal"
                >
                  Predeterminada: {seriesQuery.data.default_series}
                </Badge>
              ) : null
            }
          >
        <div className="space-y-5">
          <div className="rounded-lg border bg-muted/10 px-4 py-3">
            <p className="text-sm font-medium">
              Numeración fiscal
            </p>

            <p className="mt-1 text-xs leading-5 text-muted-foreground">
              Kova guarda la serie y el folio por separado. Por ejemplo,
              <span className="font-medium text-foreground"> FAC + 3 </span>
              se muestra como
              <span className="font-medium text-foreground"> FAC-000003</span>.
              El formato con ceros es únicamente visual.
            </p>
          </div>

          {seriesQuery.isPending ? (
            <div className="space-y-2">
              <Skeleton className="h-14 w-full rounded-lg" />
              <Skeleton className="h-14 w-full rounded-lg" />
            </div>
          ) : seriesQuery.isError ? (
            <Alert variant="destructive">
              <AlertCircleIcon />

              <AlertDescription>
                No pudimos cargar las series fiscales.
              </AlertDescription>
            </Alert>
          ) : (
            <div className="overflow-hidden rounded-lg border">
              {seriesQuery.data?.series.map(
                (item) => (
                  <div
                    key={item.series}
                    className="flex flex-col gap-3 border-b px-4 py-3 last:border-b-0 sm:flex-row sm:items-center sm:justify-between"
                  >
                    <div className="min-w-0">
                      <div className="flex flex-wrap items-center gap-2">
                        <p className="text-sm font-semibold">
                          {item.series}
                        </p>

                        {item.is_default ? (
                          <Badge variant="outline">
                            Predeterminada
                          </Badge>
                        ) : null}
                      </div>

                      <p className="mt-1 text-xs text-muted-foreground">
                        Siguiente folio: {item.next_number}
                      </p>
                    </div>

                    {!item.is_default ? (
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-10 shrink-0"
                        disabled={
                          !canManage ||
                          setDefaultSeries.isPending
                        }
                        onClick={() =>
                          handleSetDefaultSeries(
                            item.series
                          )
                        }
                      >
                        Usar como predeterminada
                      </Button>
                    ) : null}
                  </div>
                )
              )}
            </div>
          )}

          <form
            className="flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-start"
            onSubmit={submitSeries}
          >
            <div className="flex-1 space-y-2">
              <Label htmlFor="new-fiscal-series">
                Nueva serie
              </Label>

              <Input
                id="new-fiscal-series"
                value={newSeries}
                maxLength={25}
                placeholder="Ej. FAC"
                autoComplete="off"
                className="h-10"
                disabled={
                  !canManage ||
                  createSeries.isPending
                }
                aria-invalid={
                  Boolean(seriesError)
                }
                onChange={(event) => {
                  setSeriesError(null)

                  setNewSeries(
                    event.target.value
                      .toUpperCase()
                      .replace(
                        /[^A-Z0-9]/g,
                        ''
                      )
                  )
                }}
              />

              <p
                className={
                  seriesError
                    ? 'text-xs leading-5 text-destructive'
                    : 'text-xs leading-5 text-muted-foreground'
                }
              >
                {seriesError ??
                  'Usa una clave corta con letras y números. Una serie nueva inicia en el folio 1.'}
              </p>
            </div>

            <Button
              type="submit"
              size="sm"
              className="h-10 shrink-0 sm:mt-7"
              disabled={!canCreateSeries}
            >
              {createSeries.isPending
                ? 'Creando...'
                : 'Crear serie'}
            </Button>
          </form>
        </div>
          </Section>
        </div>

        <div className="overflow-hidden rounded-xl bg-card ring-1 ring-foreground/10">
          <Section
            title="Certificado de Sello Digital"
            description="Carga el Certificado de Sello Digital utilizado para firmar tus CFDI."
            aside={
              profileQuery.data
                ?.certificate ? (
                <Badge
                  variant="outline"
                  className="font-normal"
                >
                  Vigente hasta{' '}
                  {formatDate(
                    profileQuery.data
                      .certificate
                      .valid_until
                  )}
                </Badge>
              ) : null
            }
          >
        {profileQuery.data
          ?.certificate &&
          !replacingCsd ? (
          <CertificateSummary
            certificate={
              profileQuery.data
                .certificate
            }
            canManage={
              canManage
            }
            onReplace={() =>
              setReplacingCsd(
                true
              )
            }
          />
        ) : (
          <>
            <CsdForm
              disabled={
                !canManage ||
                uploadCertificate.isPending ||
                !profileQuery.data
                  ?.configuration_valid
              }
              busy={
                uploadCertificate.isPending
              }
              onSubmit={(data) => {
                if (
                  !canManage ||
                  uploadCertificate.isPending ||
                  !profileQuery.data
                    ?.configuration_valid
                ) {
                  return
                }

                uploadCertificate.mutate(
                  data
                )
              }}
            />

            {profileQuery.data
              ?.certificate &&
              replacingCsd && (
                <div className="mt-4 flex justify-end">
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-10 shrink-0"
                    disabled={
                      uploadCertificate.isPending
                    }
                    onClick={() =>
                      setReplacingCsd(
                        false
                      )
                    }
                  >
                    Cancelar
                  </Button>
                </div>
              )}
          </>
        )}

        {!profileQuery.data
          ?.configuration_valid && (
            <div className="mt-4 rounded-lg border border-dashed bg-muted/20 px-4 py-3">
              <p className="text-xs leading-5 text-muted-foreground">
                Primero guarda una configuración fiscal válida para habilitar
                la carga del Certificado de Sello Digital.
              </p>
            </div>
          )}
          </Section>
        </div>
      </div>
    </div>
  )
}

function Section({
  title,
  description,
  aside,
  children,
}: {
  title: string
  description: string
  aside?: React.ReactNode
  children: React.ReactNode
}) {
  return (
    <section className="border-t first:border-t-0">
      <header className="flex flex-col gap-3 px-5 pb-0 pt-6 sm:flex-row sm:items-start sm:justify-between sm:px-6">
        <div className="min-w-0">
          <h2 className="text-base font-semibold tracking-tight">
            {title}
          </h2>

          <p className="mt-1 max-w-2xl text-sm leading-5 text-muted-foreground">
            {description}
          </p>
        </div>

        {aside}
      </header>

      <div className="p-5 sm:p-6">
        {children}
      </div>
    </section>
  )
}

function FormField({
  label,
  description,
  tone = 'muted',
  children,
}: {
  label: string
  description?: string
  tone?:
  | 'muted'
  | 'destructive'
  | 'success'
  children: React.ReactNode
}) {
  const invalid = tone === 'destructive'

  return (
    <UiField data-invalid={invalid}>
      <FieldLabel>
        {label}
      </FieldLabel>

      {children}

      {description && invalid ? (
        <FieldError className="text-xs leading-5">
          {description}
        </FieldError>
      ) : description ? (
        <FieldDescription
          className={
            tone === 'success'
              ? 'text-xs leading-5 text-emerald-700'
              : 'text-xs leading-5'
          }
        >
          {description}
        </FieldDescription>
      ) : null}
    </UiField>
  )
}

function CertificateSummary({
  certificate,
  canManage,
  onReplace,
}: {
  certificate: NonNullable<
    FiscalProfile['certificate']
  >
  canManage: boolean
  onReplace: () => void
}) {
  return (
    <div className="space-y-5">
      <div>
        <p className="text-sm font-medium">
          Certificado configurado
        </p>

        <p className="mt-1 text-sm leading-5 text-muted-foreground">
          El Certificado de Sello Digital está almacenado y disponible para
          la emisión de CFDI.
        </p>
      </div>

      <div className="grid overflow-hidden rounded-lg border md:grid-cols-3 md:divide-x">
        <CertificateItem
          label="Número de certificado"
          value={
            certificate.serial_number
          }
        />

        <CertificateItem
          label="Vigente desde"
          value={
            formatDate(
              certificate.valid_from
            )
          }
        />

        <CertificateItem
          label="Vigente hasta"
          value={
            formatDate(
              certificate.valid_until
            )
          }
        />
      </div>

      <div className="flex items-center justify-between border-t pt-4">
        <div className="flex items-center gap-2">
          <span
            className={
              certificate.status ===
                'active'
                ? 'size-2 rounded-full bg-emerald-500'
                : 'size-2 rounded-full bg-muted-foreground'
            }
          />

          <span className="text-xs text-muted-foreground">
            {certificate.status ===
              'active'
              ? 'Certificado activo'
              : certificate.status}
          </span>
        </div>

        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-10 shrink-0"
          disabled={!canManage}
          onClick={onReplace}
        >
          Reemplazar CSD
        </Button>
      </div>
    </div>
  )
}

function CertificateItem({
  label,
  value,
}: {
  label: string
  value: string
}) {
  return (
    <div className="border-b px-4 py-3 last:border-b-0 md:border-b-0">
      <p className="text-xs text-muted-foreground">
        {label}
      </p>

      <p className="mt-1 break-all text-sm font-medium">
        {value}
      </p>
    </div>
  )
}

function FilePicker({
  id,
  name,
  label,
  accept,
  file,
  disabled,
  error,
  help,
  onChange,
}: {
  id: string
  name: string
  label: string
  accept: string
  file: File | null
  disabled: boolean
  error?: string
  help: string
  onChange: (file: File | null) => void
}) {
  return (
    <UiField data-invalid={Boolean(error)}>
      <FieldLabel htmlFor={id}>{label}</FieldLabel>

      <div className="flex min-h-10 items-center gap-3 rounded-lg border border-input bg-background px-2.5 py-1.5 transition-colors focus-within:border-ring focus-within:ring-3 focus-within:ring-ring/50 has-[:disabled]:bg-input/50 has-[:disabled]:opacity-50">
        <input
          id={id}
          name={name}
          type="file"
          accept={accept}
          required
          disabled={disabled}
          className="sr-only"
          aria-invalid={Boolean(error)}
          onChange={(event) => onChange(event.target.files?.[0] ?? null)}
        />

        <label
          htmlFor={disabled ? undefined : id}
          aria-disabled={disabled}
          className={buttonVariants({
            variant: 'outline',
            size: 'sm',
            className: disabled
              ? 'pointer-events-none shrink-0 opacity-50'
              : 'shrink-0 cursor-pointer',
          })}
        >
          Elegir archivo
        </label>

        <span className="min-w-0 truncate text-sm text-muted-foreground">
          {file?.name ?? 'Ningún archivo seleccionado'}
        </span>
      </div>

      {error ? (
        <FieldError className="text-xs leading-5">{error}</FieldError>
      ) : (
        <FieldDescription className="text-xs leading-5">{help}</FieldDescription>
      )}
    </UiField>
  )
}

function CsdForm({
  disabled,
  busy,
  onSubmit,
}: {
  disabled: boolean
  busy: boolean
  onSubmit: (
    data: FormData
  ) => void
}) {
  const [certificate, setCertificate] =
    useState<File | null>(null)

  const [privateKey, setPrivateKey] =
    useState<File | null>(null)

  const [password, setPassword] =
    useState('')

  const [errors, setErrors] =
    useState<{
      certificate?: string
      private_key?: string
      password?: string
    }>({})

  const hasRequiredData =
    Boolean(
      certificate &&
      privateKey &&
      password.length > 0
    )

  const canSubmit =
    !disabled &&
    !busy &&
    hasRequiredData

  function submit(
    event: FormEvent<HTMLFormElement>
  ) {
    event.preventDefault()

    if (
      disabled ||
      busy
    ) {
      return
    }

    const nextErrors: {
      certificate?: string
      private_key?: string
      password?: string
    } = {}

    if (!certificate) {
      nextErrors.certificate =
        'Selecciona el certificado .cer.'
    } else if (
      !certificate.name
        .toLowerCase()
        .endsWith('.cer')
    ) {
      nextErrors.certificate =
        'El certificado debe ser un archivo .cer.'
    }

    if (!privateKey) {
      nextErrors.private_key =
        'Selecciona la llave privada .key.'
    } else if (
      !privateKey.name
        .toLowerCase()
        .endsWith('.key')
    ) {
      nextErrors.private_key =
        'La llave privada debe ser un archivo .key.'
    }

    if (password.length === 0) {
      nextErrors.password =
        'Ingresa la contraseña del CSD.'
    }

    setErrors(nextErrors)

    if (
      Object.keys(nextErrors)
        .length > 0
    ) {
      return
    }

    if (
      !certificate ||
      !privateKey
    ) {
      return
    }

    const data =
      new FormData()

    data.append(
      'certificate',
      certificate
    )

    data.append(
      'private_key',
      privateKey
    )

    /*
     * No hacemos trim porque la contraseña
     * puede contener espacios válidos.
     */
    data.append(
      'password',
      password
    )

    onSubmit(data)
  }

  return (
    <form
      className="grid gap-x-5 gap-y-5 md:grid-cols-2"
      onSubmit={submit}
    >
      <FilePicker
        id="fiscal-certificate"
        name="certificate"
        label="Certificado .cer"
        accept=".cer"
        file={certificate}
        disabled={disabled || busy}
        error={errors.certificate}
        help="Certificado de Sello Digital emitido por el SAT."
        onChange={(file) => {
          setErrors((current) => ({ ...current, certificate: undefined }))
          setCertificate(file)
        }}
      />

      <FilePicker
        id="fiscal-private-key"
        name="private_key"
        label="Llave privada .key"
        accept=".key"
        file={privateKey}
        disabled={disabled || busy}
        error={errors.private_key}
        help="Llave privada correspondiente al certificado."
        onChange={(file) => {
          setErrors((current) => ({ ...current, private_key: undefined }))
          setPrivateKey(file)
        }}
      />

      <FormField
        label="Contraseña del CSD"
        description={
          errors.password ??
          'Contraseña que estableciste al generar el Certificado de Sello Digital.'
        }
        tone={
          errors.password
            ? 'destructive'
            : 'muted'
        }
      >
        <Input
          required
          name="password"
          type="password"
          autoComplete="off"
          value={password}
          placeholder="Ingresa la contraseña de tu llave privada"
          disabled={disabled || busy}
          className="h-10"
          aria-invalid={
            Boolean(
              errors.password
            )
          }
          onChange={(event) => {
            setErrors(
              (current) => ({
                ...current,
                password:
                  undefined,
              })
            )

            setPassword(
              event.target.value
            )
          }}
        />
      </FormField>

      <div className="flex items-start md:pt-7">
        <Button
          size="sm"
          type="submit"
          className="h-10 shrink-0"
          disabled={!canSubmit}
        >
          {busy
            ? 'Validando...'
            : 'Validar y guardar CSD'}
        </Button>
      </div>
    </form>
  )
}

function Status({
  profile,
}: {
  profile:
  | FiscalProfile
  | undefined
}) {
  const blocked =
    profile?.status ===
    'blocked'

  const complete =
    Boolean(
      profile?.configuration_valid
    ) &&
    profile?.certificate
      ?.status === 'active'

  return (
    <div className="inline-flex shrink-0 rounded-full border bg-background px-3 py-1.5 text-xs font-medium">
      <span
        className={
          complete
            ? 'text-emerald-700'
            : blocked
              ? 'text-destructive'
              : 'text-muted-foreground'
        }
      >
        {complete
          ? 'Configuración completa'
          : blocked
            ? 'Requiere atención'
            : 'Configuración pendiente'}
      </span>
    </div>
  )
}

function fromProfile(
  profile: FiscalProfile
): FiscalProfileInput {
  return {
    rfc:
      profile.rfc ?? '',
    legal_name:
      profile.legal_name ?? '',
    tax_regime_code:
      profile.tax_regime_code
        ? String(
          profile.tax_regime_code
        )
        : '',
    postal_code:
      profile.postal_code ?? '',
    fiscal_email:
      profile.fiscal_email ?? '',
    default_product_service_code:
      profile.default_product_service_code
        ? String(
          profile.default_product_service_code
        )
        : '',
    default_unit_code:
      profile.default_unit_code
        ? String(
          profile.default_unit_code
        )
        : '',
    default_tax_object_code:
      profile.default_tax_object_code
        ? String(
          profile.default_tax_object_code
        )
        : '',
  }
}

function normalizeForm(
  form: FiscalProfileInput
): FiscalProfileInput {
  return {
    rfc:
      form.rfc
        .trim()
        .toUpperCase(),
    legal_name:
      form.legal_name.trim(),
    tax_regime_code:
      form.tax_regime_code.trim(),
    postal_code:
      form.postal_code.trim(),
    fiscal_email:
      form.fiscal_email
        .trim()
        .toLowerCase(),
    default_product_service_code:
      form.default_product_service_code.trim(),
    default_unit_code:
      form.default_unit_code
        .trim()
        .toUpperCase(),
    default_tax_object_code:
      form.default_tax_object_code.trim(),
  }
}

function mergeCatalogOptions(
  entries:
    | CatalogOption[]
    | undefined,
  current:
    | CatalogOption
    | null
    | undefined
): CatalogOption[] {
  const options =
    (entries ?? []).map(
      (entry) => ({
        code: String(
          entry.code
        ),
        description:
          entry.description,
      })
    )

  if (!current) {
    return options
  }

  const currentOption = {
    code: String(
      current.code
    ),
    description:
      current.description,
  }

  if (
    options.some(
      (entry) =>
        entry.code ===
        currentOption.code
    )
  ) {
    return options
  }

  return [
    currentOption,
    ...options,
  ]
}

function findCatalogOption(
  options: CatalogOption[],
  value:
    | string
    | null
    | undefined
): CatalogOption | null {
  if (!value) {
    return null
  }

  const code =
    String(value).trim()

  return (
    options.find(
      (entry) =>
        entry.code === code
    ) ?? null
  )
}

function catalogLabel(
  option:
    | CatalogOption
    | null
    | undefined,
  value: string
): string | null {
  if (
    !option ||
    String(option.code) !==
    value
  ) {
    return null
  }

  return option.description
}

function postalDescription(
  state: PostalState
): string {
  switch (state) {
    case 'checking':
      return 'Validando con el catálogo SAT...'

    case 'valid':
      return 'Código postal fiscal válido.'

    case 'invalid':
      return 'El código postal no corresponde a un código vigente del SAT.'

    default:
      return 'Código postal registrado en tu domicilio fiscal.'
  }
}

function formatDate(
  value: string
) {
  return new Date(
    value
  ).toLocaleDateString(
    'es-MX',
    {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    }
  )
}

function validationErrorsOf(
  error: unknown
): FormErrors {
  if (
    typeof error !==
    'object' ||
    !error ||
    !('response' in error)
  ) {
    return {}
  }

  const response =
    (
      error as {
        response?: {
          data?: {
            errors?: Record<
              string,
              string[]
            >
          }
        }
      }
    ).response

  const errors =
    response?.data?.errors

  if (!errors) {
    return {}
  }

  const mapped: FormErrors =
    {}

  for (
    const [
      field,
      messages,
    ] of Object.entries(
      errors
    )
  ) {
    if (
      field in defaults &&
      messages?.[0]
    ) {
      mapped[
        field as keyof FiscalProfileInput
      ] = messages[0]
    }
  }

  return mapped
}

function errorOf(
  error: unknown
): string | null {
  if (
    typeof error ===
    'object' &&
    error &&
    'response' in error
  ) {
    const response =
      (
        error as {
          response?: {
            data?: {
              message?: string
              errors?: Record<
                string,
                string[]
              >
            }
          }
        }
      ).response

    const errors =
      response?.data?.errors

    if (errors) {
      const first =
        Object.values(
          errors
        )[0]?.[0]

      if (first) {
        return first
      }
    }

    return (
      response?.data
        ?.message ??
      null
    )
  }

  return error
    ? 'No pudimos completar la operación fiscal.'
    : null
}
