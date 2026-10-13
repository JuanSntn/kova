import { QrCodeIcon } from 'lucide-react'
import { cn } from '@/lib/utils'
import type { TicketSettings, TicketSettingsMeta } from '../types'

export function TicketPreview({
    settings,
    meta,
}: {
    settings: TicketSettings
    meta: TicketSettingsMeta
}) {
    const business = meta.business
    const visibility = settings.visibility

    const invoiceEntryUrl =
        typeof window !== 'undefined'
            ? `${window.location.origin}/factura`
            : '/factura'

    const showInvoiceQr =
        settings.invoice.show_qr &&
        meta.capabilities.invoice_qr &&
        meta.invoice_qr.ready

    return (
        <div className="space-y-3">
            <div>
                <h2 className="text-sm font-semibold">Vista previa</h2>
                <p className="mt-1 text-xs text-muted-foreground">
                    Los cambios se reflejan aquí antes de guardar.
                </p>
            </div>

            <div className="rounded-xl border bg-muted/20 p-3">
                <div className="max-h-[calc(100vh-12rem)] overflow-y-auto pr-1">
                    <div className="mx-auto flex w-full max-w-[340px] justify-center py-3">
                        <div
                            className={cn(
                                'relative bg-background font-mono text-[10px] leading-relaxed text-foreground shadow-sm',
                                settings.printing.paper_width_mm === 58
                                    ? 'w-[220px]'
                                    : 'w-[280px]',
                            )}
                        >
                            <div className="px-4 pt-6 pb-4">
                                <div className="text-center">
                                    {visibility.logo && business.logo_url && (
                                        <img
                                            src={business.logo_url}
                                            alt=""
                                            className="mx-auto mb-2 max-h-10 max-w-24 object-contain"
                                        />
                                    )}

                                    {visibility.business_name && (
                                        <p className="text-xs font-bold tracking-wide">
                                            {business.name}
                                        </p>
                                    )}

                                    {visibility.phone && business.phone && (
                                        <p className="mt-1 text-[9px]">
                                            {business.phone}
                                        </p>
                                    )}

                                    {visibility.email && business.public_email && (
                                        <p className="break-all text-[9px]">
                                            {business.public_email}
                                        </p>
                                    )}

                                    {settings.header_text && (
                                        <p className="mt-2 whitespace-pre-wrap text-[9px]">
                                            {settings.header_text}
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

                                {visibility.order_type && meta.capabilities.order_type && (
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

                                    <div className="mt-1 flex justify-between gap-3">
                                        <span>
                                            {visibility.unit_price
                                                ? '2 × $150.00'
                                                : '2 pza'}
                                        </span>

                                        <span className="shrink-0">$300.00</span>
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

                                <div className="mt-2 flex justify-between text-[11px] font-bold">
                                    <span>TOTAL</span>
                                    <span>$300.00</span>
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

                                {showInvoiceQr && (
                                    <>
                                        <Divider />

                                        <div className="flex flex-col items-center text-center">
                                            <QrCodeIcon className="size-14" />

                                            <p className="mt-2 max-w-[12rem] text-[8px] leading-3">
                                                {settings.invoice.qr_text ||
                                                    'Escanea para solicitar tu factura.'}
                                            </p>

                                            <div className="mt-2">
                                                <p className="text-[7px] text-muted-foreground">
                                                    Código de facturación
                                                </p>

                                                <p className="mt-0.5 font-mono text-[8px] font-bold tracking-[0.18em]">
                                                    K7PT-4M9X-2QW8
                                                </p>
                                            </div>

                                            <div className="mt-2 max-w-[12rem] text-[7px] leading-3">
                                                <p>También puedes ingresar en:</p>

                                                <p className="break-all font-medium">
                                                    {invoiceEntryUrl}
                                                </p>
                                            </div>
                                        </div>
                                    </>
                                )}

                                {settings.additional_text && (
                                    <>
                                        <Divider />
                                        <p className="whitespace-pre-wrap text-center text-[9px]">
                                            {settings.additional_text}
                                        </p>
                                    </>
                                )}

                                {settings.footer_text && (
                                    <>
                                        <Divider />
                                        <p className="whitespace-pre-wrap text-center text-[9px]">
                                            {settings.footer_text}
                                        </p>
                                    </>
                                )}
                            </div>

                            <div className="relative h-4 w-full overflow-hidden bg-background">
                                <div className="absolute inset-x-0 top-0 flex justify-between">
                                    {Array.from({
                                        length:
                                            settings.printing.paper_width_mm === 58
                                                ? 10
                                                : 14,
                                    }).map((_, index) => (
                                        <span
                                            key={index}
                                            className="block h-4 w-4 bg-muted/20"
                                            style={{
                                                clipPath: 'polygon(50% 100%, 0 0, 100% 0)',
                                            }}
                                        />
                                    ))}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    )
}

function Divider() {
    return <div className="my-3 border-t border-dashed" />
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
            <span>{label}</span>
            <span className="text-right">{value}</span>
        </div>
    )
}
