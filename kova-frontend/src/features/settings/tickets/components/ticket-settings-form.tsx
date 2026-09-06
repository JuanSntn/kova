import {
    useEffect,
    useState,
} from 'react'

import {
    LoaderCircleIcon,
    SaveIcon,
} from 'lucide-react'

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
    TicketSettingsMeta,
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
            response.data
        )
    }, [
        response.data,
    ])

    const meta =
        response.meta

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
            })
        )
    }

    async function save() {
        try {
            const saved =
                await onSave(
                    toPayload(
                        form
                    )
                )

            setForm(
                saved.data
            )

            toast.success(
                'Configuración del ticket guardada.',
            )
        } catch {
            toast.error(
                'No pudimos guardar la configuración del ticket.',
            )
        }
    }

    return (
        <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
            <div className="space-y-5">
                {/*
                |--------------------------------------------------------------------------
                | General
                |--------------------------------------------------------------------------
                */}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            General
                        </CardTitle>
                    </CardHeader>

                    <CardContent className="space-y-4">
                        <Field
                            label="Prefijo del folio"
                        >
                            <Input
                                value={
                                    form.folio_prefix
                                }
                                maxLength={
                                    10
                                }
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
                                        })
                                    )
                                }
                            />
                        </Field>

                        <Field
                            label="Texto de encabezado"
                        >
                            <Input
                                value={
                                    form.header_text ??
                                    ''
                                }
                                maxLength={
                                    255
                                }
                                onChange={event =>
                                    setForm(
                                        current => ({
                                            ...current,
                                            header_text:
                                                event.target.value,
                                        })
                                    )
                                }
                            />
                        </Field>

                        <Field
                            label="Texto adicional"
                        >
                            <Textarea
                                rows={3}
                                value={
                                    form.additional_text ??
                                    ''
                                }
                                onChange={event =>
                                    setForm(
                                        current => ({
                                            ...current,
                                            additional_text:
                                                event.target.value,
                                        })
                                    )
                                }
                            />
                        </Field>

                        <Field
                            label="Pie del ticket"
                        >
                            <Textarea
                                rows={3}
                                value={
                                    form.footer_text ??
                                    ''
                                }
                                onChange={event =>
                                    setForm(
                                        current => ({
                                            ...current,
                                            footer_text:
                                                event.target.value,
                                        })
                                    )
                                }
                            />
                        </Field>
                    </CardContent>
                </Card>

                {/*
                |--------------------------------------------------------------------------
                | Business
                |--------------------------------------------------------------------------
                */}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Información del negocio
                        </CardTitle>
                    </CardHeader>

                    <CardContent>
                        <SettingSwitch
                            label="Logo"
                            description={
                                meta.business.logo_url
                                    ? undefined
                                    : 'Sube primero un logo en Información del negocio.'
                            }
                            checked={
                                Boolean(
                                    meta.business
                                        .logo_url
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
                | Sale
                |--------------------------------------------------------------------------
                */}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Información de la venta
                        </CardTitle>
                    </CardHeader>

                    <CardContent>
                        <SettingSwitch
                            label="Folio"
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
                 * Facturación NO aparece todavía.
                 *
                 * Incluso en plan pagado esperamos
                 * a que fiscal_ready sea true.
                 */}

                {meta.capabilities
                    .invoice_qr &&
                    meta.sources
                        .fiscal_ready && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Facturación
                                </CardTitle>
                            </CardHeader>

                            <CardContent>
                                <SettingSwitch
                                    label="Mostrar QR de facturación"
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
                                            })
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
                    <CardHeader>
                        <CardTitle className="text-base">
                            Impresión
                        </CardTitle>
                    </CardHeader>

                    <CardContent className="space-y-4">
                        <Field
                            label="Ancho de papel"
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
                                        })
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

                        <Field label="Copias">
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
                                        })
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

                        <SettingSwitch
                            label="Imprimir automáticamente"
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
                                    })
                                )
                            }
                        />
                    </CardContent>
                </Card>

                <div className="flex justify-end border-t pt-5">
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
    return (
        <div className="flex min-h-14 items-center justify-between gap-4 border-b py-3 last:border-b-0">
            <div>
                <Label>
                    {label}
                </Label>

                {description && (
                    <p className="mt-1 text-xs text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>

            <Switch
                checked={
                    checked
                }
                disabled={
                    disabled
                }
                onCheckedChange={
                    onChange
                }
            />
        </div>
    )
}

function Field({
    label,
    children,
}: {
    label: string
    children:
    React.ReactNode
}) {
    return (
        <div className="space-y-1.5">
            <Label>
                {label}
            </Label>

            {children}
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
                settings.header_text
            ),

        footer_text:
            nullable(
                settings.footer_text
            ),

        additional_text:
            nullable(
                settings.additional_text
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
                settings.invoice.qr_text
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