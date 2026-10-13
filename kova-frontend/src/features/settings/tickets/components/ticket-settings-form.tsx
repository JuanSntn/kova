import {
    type ReactNode,
    useId,
    useEffect,
    useState,
} from 'react'

import {
    Building2Icon,
    FileTextIcon,
    LoaderCircleIcon,
    PrinterIcon,
    ReceiptTextIcon,
    SaveIcon,
} from 'lucide-react'
import axios from 'axios'

import {
    toast,
} from 'sonner'

import {
    Button,
} from '@/components/ui/button'

import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card'

import {
    Input,
} from '@/components/ui/input'

import {
    Label,
} from '@/components/ui/label'

import {
    Switch,
} from '@/components/ui/switch'

import {
    Textarea,
} from '@/components/ui/textarea'

import {
    TicketPreview,
} from './ticket-preview'

import type {
    TicketSettings,
    TicketSettingsResponse,
    UpdateTicketSettingsPayload,
} from '../types'

export function TicketSettingsForm({
    response,
    isSaving,
    onSave,
}: {
    response:
    TicketSettingsResponse

    isSaving: boolean

    onSave: (
        payload:
            UpdateTicketSettingsPayload,
    ) =>
        Promise<TicketSettingsResponse>
}) {
    const [
        form,
        setForm,
    ] =
        useState<TicketSettings>(
            response.data,
        )

    useEffect(() => {
        setForm(
            response.data,
        )
    }, [
        response.data,
    ])

    const meta =
        response.meta
    const [saveError, setSaveError] = useState<string | null>(null)

    function visibility(
        key:
            keyof TicketSettings[
            'visibility'
            ],
        value: boolean,
    ) {
        setForm(
            current => ({
                ...current,

                visibility: {
                    ...current.visibility,

                    [key]:
                        value,
                },
            }),
        )
    }

    async function save() {
        setSaveError(null)
        try {
            const saved =
                await onSave(
                    toPayload(
                        form,
                    ),
                )

            setForm(
                saved.data,
            )

            toast.success(
                'Configuración del ticket guardada.',
            )
        } catch (error) {
            const message = axios.isAxiosError<{ message?: string }>(error)
                    ? error.response?.data?.message ?? 'No pudimos guardar la configuración del ticket.'
                    : 'No pudimos guardar la configuración del ticket.'
            setSaveError(message)
            toast.error(message)
        }
    }

    return (
        <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
            {/*
            |--------------------------------------------------------------------------
            | Settings
            |--------------------------------------------------------------------------
            */}

            <div className="space-y-5">
                {saveError && <p role="alert" className="text-sm text-destructive">{saveError}</p>}
                {/*
                |--------------------------------------------------------------------------
                | General
                |--------------------------------------------------------------------------
                */}

                <Card>
                    <CardHeader className="border-b">
                        <div className="flex items-start gap-3">
                            <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted">
                                <FileTextIcon className="size-4 text-muted-foreground" />
                            </div>

                            <div className="min-w-0">
                                <CardTitle className="text-base">
                                    General
                                </CardTitle>

                                <p className="mt-1 text-sm leading-6 text-muted-foreground">
                                    Define la identificación y los textos
                                    principales que aparecerán en tus tickets.
                                </p>
                            </div>
                        </div>
                    </CardHeader>

                    <CardContent className="space-y-7 pt-6">
                        <Field
                            label="Prefijo del folio"
                            description="Identificador corto que aparecerá antes del número de venta."
                        >
                            <Input
                                value={
                                    form.folio_prefix
                                }
                                maxLength={
                                    10
                                }
                                placeholder="Ej. KV"
                                onChange={event =>
                                    setForm(
                                        current => ({
                                            ...current,

                                            folio_prefix:
                                                event.target.value
                                                    .replace(
                                                        /[^A-Za-z0-9]/g,
                                                        '',
                                                    )
                                                    .toUpperCase(),
                                        }),
                                    )
                                }
                            />
                        </Field>

                        <div className="border-t" />

                        <Field
                            label="Texto de encabezado"
                            description="Mensaje opcional que se mostrará en la parte superior del ticket."
                        >
                            <Input
                                value={
                                    form.header_text ??
                                    ''
                                }
                                maxLength={
                                    255
                                }
                                placeholder="Ej. Gracias por visitarnos"
                                onChange={event =>
                                    setForm(
                                        current => ({
                                            ...current,

                                            header_text:
                                                event.target.value,
                                        }),
                                    )
                                }
                            />
                        </Field>

                        <div className="border-t" />

                        <Field
                            label="Texto adicional"
                            description="Información complementaria que quieras comunicar al cliente."
                        >
                            <Textarea
                                rows={3}
                                value={
                                    form.additional_text ??
                                    ''
                                }
                                placeholder="Información adicional del ticket..."
                                onChange={event =>
                                    setForm(
                                        current => ({
                                            ...current,

                                            additional_text:
                                                event.target.value,
                                        }),
                                    )
                                }
                            />
                        </Field>

                        <div className="border-t" />

                        <Field
                            label="Pie del ticket"
                            description="Mensaje final que aparecerá después del detalle de la venta."
                        >
                            <Textarea
                                rows={3}
                                value={
                                    form.footer_text ??
                                    ''
                                }
                                placeholder="Ej. ¡Gracias por tu compra!"
                                onChange={event =>
                                    setForm(
                                        current => ({
                                            ...current,

                                            footer_text:
                                                event.target.value,
                                        }),
                                    )
                                }
                            />
                        </Field>
                    </CardContent>
                </Card>

                {/*
                |--------------------------------------------------------------------------
                | Business information
                |--------------------------------------------------------------------------
                */}

                <Card>
                    <CardHeader className="border-b">
                        <div className="flex items-start gap-3">
                            <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted">
                                <Building2Icon className="size-4 text-muted-foreground" />
                            </div>

                            <div className="min-w-0">
                                <CardTitle className="text-base">
                                    Información del negocio
                                </CardTitle>

                                <p className="mt-1 text-sm leading-6 text-muted-foreground">
                                    Elige qué datos públicos de tu negocio
                                    quieres mostrar a tus clientes.
                                </p>
                            </div>
                        </div>
                    </CardHeader>

                    <CardContent className="divide-y">
                        <SettingSwitch
                            label="Logo"
                            description={
                                meta.business.logo_url
                                    ? 'Muestra el logo configurado para tu negocio.'
                                    : 'Sube primero un logo en Información del negocio.'
                            }
                            checked={
                                Boolean(
                                    meta.business
                                        .logo_url,
                                ) &&
                                form.visibility
                                    .logo
                            }
                            disabled={
                                !meta.business
                                    .logo_url
                            }
                            onChange={value =>
                                visibility(
                                    'logo',
                                    value,
                                )
                            }
                        />

                        <SettingSwitch
                            label="Nombre del negocio"
                            description="Muestra el nombre público configurado para tu negocio."
                            checked={
                                form.visibility
                                    .business_name
                            }
                            onChange={value =>
                                visibility(
                                    'business_name',
                                    value,
                                )
                            }
                        />

                        <SettingSwitch
                            label="Teléfono"
                            description="Muestra el número público de contacto."
                            checked={
                                form.visibility
                                    .phone
                            }
                            onChange={value =>
                                visibility(
                                    'phone',
                                    value,
                                )
                            }
                        />

                        <SettingSwitch
                            label="Correo"
                            description="Muestra el correo público configurado para tus clientes."
                            checked={
                                form.visibility
                                    .email
                            }
                            onChange={value =>
                                visibility(
                                    'email',
                                    value,
                                )
                            }
                        />
                    </CardContent>
                </Card>

                {/*
                |--------------------------------------------------------------------------
                | Sale information
                |--------------------------------------------------------------------------
                */}

                <Card>
                    <CardHeader className="border-b">
                        <div className="flex items-start gap-3">
                            <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted">
                                <ReceiptTextIcon className="size-4 text-muted-foreground" />
                            </div>

                            <div className="min-w-0">
                                <CardTitle className="text-base">
                                    Información de la venta
                                </CardTitle>

                                <p className="mt-1 text-sm leading-6 text-muted-foreground">
                                    Selecciona los datos de la operación que
                                    deben aparecer impresos en cada ticket.
                                </p>
                            </div>
                        </div>
                    </CardHeader>

                    <CardContent className="divide-y">
                        <SettingSwitch
                            label="Folio"
                            description="Identificador único de la venta."
                            checked={
                                form.visibility
                                    .folio
                            }
                            onChange={value =>
                                visibility(
                                    'folio',
                                    value,
                                )
                            }
                        />

                        <SettingSwitch
                            label="Fecha y hora"
                            description="Fecha y hora en la que se registró la venta."
                            checked={
                                form.visibility
                                    .datetime
                            }
                            onChange={value =>
                                visibility(
                                    'datetime',
                                    value,
                                )
                            }
                        />

                        <SettingSwitch
                            label="Cajero"
                            description="Se obtiene del usuario que realizó la venta."
                            checked={
                                form.visibility
                                    .cashier
                            }
                            onChange={value =>
                                visibility(
                                    'cashier',
                                    value,
                                )
                            }
                        />

                        {meta.capabilities
                            .order_type && (
                                <SettingSwitch
                                    label="Tipo de orden"
                                    description="Muestra el tipo de servicio asociado a la venta."
                                    checked={
                                        form.visibility
                                            .order_type
                                    }
                                    onChange={value =>
                                        visibility(
                                            'order_type',
                                            value,
                                        )
                                    }
                                />
                            )}

                        <SettingSwitch
                            label="Precio unitario"
                            description="Muestra el precio individual de cada producto."
                            checked={
                                form.visibility
                                    .unit_price
                            }
                            onChange={value =>
                                visibility(
                                    'unit_price',
                                    value,
                                )
                            }
                        />

                        <SettingSwitch
                            label="Impuestos incluidos"
                            description="Muestra el desglose de impuestos de la venta."
                            checked={
                                form.visibility
                                    .tax_breakdown
                            }
                            onChange={value =>
                                visibility(
                                    'tax_breakdown',
                                    value,
                                )
                            }
                        />

                        <SettingSwitch
                            label="Método de pago"
                            description="Indica cómo pagó el cliente."
                            checked={
                                form.visibility
                                    .payment_method
                            }
                            onChange={value =>
                                visibility(
                                    'payment_method',
                                    value,
                                )
                            }
                        />

                        <SettingSwitch
                            label="Efectivo recibido"
                            description="Muestra el importe entregado por el cliente."
                            checked={
                                form.visibility
                                    .cash_received
                            }
                            onChange={value =>
                                visibility(
                                    'cash_received',
                                    value,
                                )
                            }
                        />

                        <SettingSwitch
                            label="Cambio"
                            description="Muestra el cambio entregado al cliente."
                            checked={
                                form.visibility
                                    .change
                            }
                            onChange={value =>
                                visibility(
                                    'change',
                                    value,
                                )
                            }
                        />
                    </CardContent>
                </Card>

                {/*
                |--------------------------------------------------------------------------
                | Invoicing
                |--------------------------------------------------------------------------
                |
                | Facturación sigue apareciendo únicamente cuando la
                | capacidad está disponible y fiscal_ready es true.
                |
                */}

                {meta.capabilities
                    .invoice_qr && (
                        <Card>
                            <CardHeader className="border-b">
                                <div className="flex items-start gap-3">
                                    <div className="min-w-0">
                                        <CardTitle className="text-base">
                                            Facturación
                                        </CardTitle>

                                        <p className="mt-1 text-sm leading-6 text-muted-foreground">
                                            Configura las opciones de
                                            facturación disponibles para
                                            tus clientes desde el ticket.
                                        </p>
                                    </div>
                                </div>
                            </CardHeader>

                            <CardContent>
                                <ol className="divide-y text-sm">
                                    <li className="space-y-2 py-4">
                                        <p className="font-medium">1. Datos fiscales · {meta.invoice_qr.fiscal_ready ? 'Completado' : 'Pendiente'}</p>
                                        <p className="text-muted-foreground">Valida tus datos fiscales, carga un CSD vigente y completa la conexión de facturación.</p>
                                        <a className="underline underline-offset-4" href={meta.invoice_qr.fiscal_url}>Ir a Facturación fiscal</a>
                                    </li>
                                    <li className="space-y-2 py-4">
                                        <p className="font-medium">2. Sucursales · {meta.invoice_qr.branches_ready ? 'Completado' : 'Pendiente'}</p>
                                        <p className="text-muted-foreground">Completa el código postal, dirección y zona horaria de todas las sucursales operativas.</p>
                                        {meta.invoice_qr.missing_branches.map(branch => <p key={branch.id} className="text-muted-foreground">{branch.name}: revisa su dirección y código postal.</p>)}
                                        <a className="underline underline-offset-4" href={meta.invoice_qr.branches_url}>Ir a Sucursales</a>
                                    </li>
                                    <li className="space-y-2 py-4">
                                        <p className="font-medium">3. Activar QR · {meta.invoice_qr.enabled ? 'Completado' : 'Pendiente'}</p>
                                        <p className="text-muted-foreground">{meta.invoice_qr.message}</p>
                                    </li>
                                </ol>
                                <SettingSwitch
                                    disabled={!meta.invoice_qr.ready || isSaving}
                                    label="Mostrar QR de facturación"
                                    description="Permite que el cliente acceda al flujo de facturación desde su ticket."
                                    checked={
                                        form.invoice
                                            .show_qr
                                    }
                                    onChange={value =>
                                        setForm(
                                            current => ({
                                                ...current,

                                                invoice: {
                                                    ...current.invoice,

                                                    show_qr:
                                                        value,
                                                },
                                            }),
                                        )
                                    }
                                />
                            </CardContent>
                        </Card>
                    )}

                {/*
                |--------------------------------------------------------------------------
                | Printing
                |--------------------------------------------------------------------------
                */}

                <Card>
                    <CardHeader className="border-b">
                        <div className="flex items-start gap-3">
                            <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted">
                                <PrinterIcon className="size-4 text-muted-foreground" />
                            </div>

                            <div className="min-w-0">
                                <CardTitle className="text-base">
                                    Impresión
                                </CardTitle>

                                <p className="mt-1 text-sm leading-6 text-muted-foreground">
                                    Define el formato físico y el
                                    comportamiento de impresión de tus tickets.
                                </p>
                            </div>
                        </div>
                    </CardHeader>

                    <CardContent className="space-y-7 pt-6">
                        <Field
                            label="Ancho de papel"
                            description="Selecciona el tamaño compatible con tu impresora térmica."
                        >
                            <select
                                value={
                                    form.printing
                                        .paper_width_mm
                                }
                                className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                                onChange={event =>
                                    setForm(
                                        current => ({
                                            ...current,

                                            printing: {
                                                ...current.printing,

                                                paper_width_mm:
                                                    Number(
                                                        event.target.value,
                                                    ) as 58 | 80,
                                            },
                                        }),
                                    )
                                }
                            >
                                <option value="58">
                                    58 mm
                                </option>

                                <option value="80">
                                    80 mm
                                </option>
                            </select>
                        </Field>

                        <div className="border-t" />

                        <Field
                            label="Copias"
                            description="Número de copias que quieres generar por cada impresión."
                        >
                            <select
                                value={
                                    form.printing
                                        .copies
                                }
                                className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                                onChange={event =>
                                    setForm(
                                        current => ({
                                            ...current,

                                            printing: {
                                                ...current.printing,

                                                copies:
                                                    Number(
                                                        event.target.value,
                                                    ),
                                            },
                                        }),
                                    )
                                }
                            >
                                <option value="1">
                                    1
                                </option>

                                <option value="2">
                                    2
                                </option>

                                <option value="3">
                                    3
                                </option>
                            </select>
                        </Field>

                        <div className="border-t" />

                        <SettingSwitch
                            label="Imprimir automáticamente"
                            description="Envía el ticket a impresión después de completar una venta."
                            checked={
                                form.printing
                                    .auto_print
                            }
                            onChange={value =>
                                setForm(
                                    current => ({
                                        ...current,

                                        printing: {
                                            ...current.printing,

                                            auto_print:
                                                value,
                                        },
                                    }),
                                )
                            }
                        />
                    </CardContent>
                </Card>

                {/*
                |--------------------------------------------------------------------------
                | Actions
                |--------------------------------------------------------------------------
                */}

                <div className="flex flex-col-reverse gap-2 border-t pt-5 sm:flex-row sm:items-center sm:justify-end">
                    <Button
                        type="button"
                        disabled={
                            isSaving
                        }
                        onClick={
                            save
                        }
                    >
                        {isSaving ? (
                            <LoaderCircleIcon className="size-4 animate-spin" />
                        ) : (
                            <SaveIcon className="size-4" />
                        )}

                        {isSaving
                            ? 'Guardando...'
                            : 'Guardar cambios'}
                    </Button>
                </div>
            </div>

            {/*
            |--------------------------------------------------------------------------
            | Preview
            |--------------------------------------------------------------------------
            */}

            <aside className="xl:sticky xl:top-5 xl:self-start">
                <TicketPreview
                    settings={
                        form
                    }
                    meta={
                        meta
                    }
                />
            </aside>
        </div>
    )
}

function SettingSwitch({
    label,
    description,
    checked,
    disabled = false,
    onChange,
}: {
    label: string
    description?: string
    checked: boolean
    disabled?: boolean

    onChange: (
        checked: boolean,
    ) => void
}) {
    const id = useId()
    return (
        <div className="flex min-h-16 items-center justify-between gap-6 py-4">
            <div className="min-w-0">
                <Label htmlFor={id} className="text-sm font-medium leading-5">
                    {label}
                </Label>

                {description && (
                    <p className="mt-1 max-w-xl text-xs leading-5 text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>

            <Switch
                id={id}
                checked={
                    checked
                }
                disabled={
                    disabled
                }
                onCheckedChange={
                    onChange
                }
                className="shrink-0"
            />
        </div>
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
        <div className="grid gap-3 sm:grid-cols-[180px_minmax(0,1fr)] sm:gap-6">
            <div>
                <Label className="text-sm font-medium">
                    {label}
                </Label>

                {description && (
                    <p className="mt-1 text-xs leading-5 text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>

            <div className="max-w-lg">
                {children}
            </div>
        </div>
    )
}

function nullable(
    value: string | null,
) {
    const normalized =
        value?.trim() ?? ''

    return normalized === ''
        ? null
        : normalized
}

function toPayload(
    settings:
        TicketSettings,
): UpdateTicketSettingsPayload {
    return {
        folio_prefix:
            settings.folio_prefix,

        header_text:
            nullable(
                settings.header_text,
            ),

        footer_text:
            nullable(
                settings.footer_text,
            ),

        additional_text:
            nullable(
                settings.additional_text,
            ),

        show_logo:
            settings.visibility.logo,

        show_business_name:
            settings.visibility
                .business_name,

        show_branch_name:
            settings.visibility
                .branch_name,

        show_address:
            settings.visibility
                .address,

        show_phone:
            settings.visibility.phone,

        show_email:
            settings.visibility.email,

        show_rfc:
            settings.visibility.rfc,

        show_cashier:
            settings.visibility.cashier,

        show_folio:
            settings.visibility.folio,

        show_datetime:
            settings.visibility.datetime,

        show_order_type:
            settings.visibility
                .order_type,

        show_unit_price:
            settings.visibility
                .unit_price,

        show_tax_breakdown:
            settings.visibility
                .tax_breakdown,

        show_payment_method:
            settings.visibility
                .payment_method,

        show_cash_received:
            settings.visibility
                .cash_received,

        show_change:
            settings.visibility.change,

        show_invoice_qr:
            settings.invoice.show_qr,

        invoice_qr_text:
            nullable(
                settings.invoice.qr_text,
            ),

        paper_width_mm:
            settings.printing
                .paper_width_mm,

        auto_print:
            settings.printing
                .auto_print,

        copies:
            settings.printing.copies,
    }
}
