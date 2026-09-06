import {
    QrCodeIcon,
} from 'lucide-react'

import {
    cn,
} from '@/lib/utils'

import type {
    TicketSettings,
    TicketSettingsMeta,
} from '../types'

export function TicketPreview({
    settings,
    meta,
}: {
    settings: TicketSettings
    meta: TicketSettingsMeta
}) {
    const business =
        meta.business

    const visibility =
        settings.visibility

    return (
        <div>
            <h2 className="text-sm font-semibold">
                Vista previa
            </h2>

            <p className="mt-1 text-xs text-muted-foreground">
                Los cambios se reflejan aquí antes de guardar.
            </p>

            <div className="mt-3 rounded-xl border bg-muted/20 p-5">
                <div
                    className={cn(
                        'mx-auto bg-background px-5 py-6 font-mono text-[11px] shadow-sm',

                        settings.printing
                            .paper_width_mm ===
                            58
                            ? 'max-w-[250px]'
                            : 'max-w-[320px]',
                    )}
                >
                    <div className="text-center">
                        {visibility.logo &&
                            business.logo_url && (
                                <img
                                    src={
                                        business.logo_url
                                    }
                                    alt=""
                                    className="mx-auto mb-3 max-h-14 max-w-32 object-contain"
                                />
                            )}

                        {visibility.business_name && (
                            <p className="text-sm font-bold">
                                {
                                    business.name
                                }
                            </p>
                        )}

                        {visibility.phone &&
                            business.phone && (
                                <p className="mt-1">
                                    {
                                        business.phone
                                    }
                                </p>
                            )}

                        {visibility.email &&
                            business.public_email && (
                                <p>
                                    {
                                        business.public_email
                                    }
                                </p>
                            )}

                        {settings.header_text && (
                            <p className="mt-3 whitespace-pre-wrap">
                                {
                                    settings.header_text
                                }
                            </p>
                        )}
                    </div>

                    <Divider />

                    {visibility.folio && (
                        <Line
                            label="Folio"
                            value={`${settings.folio_prefix}-000001`}
                        />
                    )}

                    {visibility.datetime && (
                        <Line
                            label="Fecha"
                            value="14/08/2026 19:30"
                        />
                    )}

                    {visibility.cashier && (
                        <Line
                            label="Cajero"
                            value="Juan"
                        />
                    )}

                    {visibility.order_type &&
                        meta.capabilities
                            .order_type && (
                            <Line
                                label="Orden"
                                value="Mostrador"
                            />
                        )}

                    <Divider />

                    <div>
                        <p className="font-medium">
                            Hamburguesa sencilla
                        </p>

                        <div className="mt-1 flex justify-between">
                            <span>
                                {visibility.unit_price
                                    ? '2 × $150.00'
                                    : '2 pza'}
                            </span>

                            <span>
                                $300.00
                            </span>
                        </div>
                    </div>

                    <Divider />

                    <Line
                        label="Subtotal"
                        value="$300.00"
                    />

                    {visibility.tax_breakdown && (
                        <Line
                            label="Impuestos incluidos"
                            value="$41.38"
                        />
                    )}

                    <div className="mt-3 flex justify-between text-xs font-bold">
                        <span>
                            TOTAL
                        </span>

                        <span>
                            $300.00
                        </span>
                    </div>

                    {(visibility.payment_method ||
                        visibility.cash_received ||
                        visibility.change) && (
                            <>
                                <Divider />

                                {visibility.payment_method && (
                                    <Line
                                        label="Método"
                                        value="Efectivo"
                                    />
                                )}

                                {visibility.cash_received && (
                                    <Line
                                        label="Recibido"
                                        value="$500.00"
                                    />
                                )}

                                {visibility.change && (
                                    <Line
                                        label="Cambio"
                                        value="$200.00"
                                    />
                                )}
                            </>
                        )}

                    {settings.additional_text && (
                        <>
                            <Divider />

                            <p className="whitespace-pre-wrap text-center">
                                {
                                    settings.additional_text
                                }
                            </p>
                        </>
                    )}

                    {settings.invoice.show_qr &&
                        meta.capabilities.invoice_qr &&
                        meta.sources.fiscal_ready && (
                            <>
                                <Divider />

                                <div className="flex flex-col items-center">
                                    <QrCodeIcon className="size-16" />

                                    <p className="mt-2 text-center text-[9px]">
                                        {
                                            settings.invoice
                                                .qr_text
                                        }
                                    </p>
                                </div>
                            </>
                        )}

                    {settings.footer_text && (
                        <>
                            <Divider />

                            <p className="whitespace-pre-wrap text-center">
                                {
                                    settings.footer_text
                                }
                            </p>
                        </>
                    )}
                </div>
            </div>
        </div>
    )
}

function Divider() {
    return (
        <div className="my-4 border-t border-dashed" />
    )
}

function Line({
    label,
    value,
}: {
    label: string
    value: string
}) {
    return (
        <div className="flex justify-between gap-3 py-0.5">
            <span>
                {label}
            </span>

            <span className="text-right">
                {value}
            </span>
        </div>
    )
}