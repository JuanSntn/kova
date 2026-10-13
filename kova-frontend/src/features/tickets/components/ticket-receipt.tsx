import { QRCodeSVG } from 'qrcode.react'

import type { TicketDocument } from '../types'

import {
    formatTicketDateTime,
    formatTicketMoney,
    ticketOrderTypeLabel,
    ticketPaymentMethodLabel,
} from '../utils'

type TicketReceiptProps = {
    ticket: TicketDocument
}

export function TicketReceipt({
    ticket,
}: TicketReceiptProps) {
    const {
        business,
        branch,
        sale,
        settings,
        timezone,
    } = ticket

    const visibility =
        settings.visibility

    const hasPaymentInfo =
        visibility.payment_method ||
        (
            sale.payment_method === 'cash' &&
            (
                visibility.cash_received ||
                visibility.change
            )
        )

    return (
        <div className="overflow-hidden rounded-xl border bg-background">
            <div className="mx-auto w-full max-w-sm px-5 py-6 font-mono text-[12px] leading-relaxed">
                <div className="text-center">
                    {visibility.logo &&
                        business.logo_url && (
                            <img
                                src={business.logo_url}
                                alt=""
                                className="mx-auto mb-3 max-h-14 max-w-36 object-contain"
                            />
                        )}

                    {visibility.business_name && (
                        <p className="text-sm font-bold">
                            {business.name}
                        </p>
                    )}

                    {visibility.branch_name && branch && (
                        <p className="text-[11px] font-semibold">Sucursal {branch.name}</p>
                    )}

                    {visibility.address && branch && (
                        <p className="text-[11px]">{formatBranchAddress(branch)}</p>
                    )}

                    {visibility.phone &&
                        business.phone && (
                            <p className="mt-1 text-[11px]">
                                {business.phone}
                            </p>
                        )}

                    {visibility.email &&
                        business.public_email && (
                            <p className="break-all text-[11px]">
                                {business.public_email}
                            </p>
                        )}

                    {settings.header_text && (
                        <p className="mt-3 whitespace-pre-line text-[11px]">
                            {settings.header_text}
                        </p>
                    )}
                </div>

                <Divider />

                <div className="space-y-1">
                    {visibility.folio &&
                        sale.folio && (
                            <TicketRow
                                label="Folio"
                                value={sale.folio}
                            />
                        )}

                    {visibility.datetime &&
                        sale.paid_at && (
                            <TicketRow
                                label="Fecha"
                                value={formatTicketDateTime(
                                    sale.paid_at,
                                    timezone,
                                )}
                            />
                        )}

                    {visibility.cashier &&
                        sale.cashier && (
                            <TicketRow
                                label="Cajero"
                                value={sale.cashier}
                            />
                        )}

                    {visibility.order_type && (
                        <TicketRow
                            label="Orden"
                            value={ticketOrderTypeLabel(
                                sale.order_type,
                            )}
                        />
                    )}
                </div>

                <Divider />

                <div className="space-y-3">
                    {sale.items.map(
                        (
                            item,
                            index,
                        ) => {
                            const modifiers = item.modifiers ?? []
                            const baseUnitPrice = item.base_unit_price ?? item.unit_price

                            return (
                            <div
                                key={`${item.name}-${index}`}
                            >
                                <p className="font-semibold">
                                    {item.name}
                                </p>

                                <div className="mt-0.5 flex items-start justify-between gap-3">
                                    <span className="text-[11px]">
                                        {visibility.unit_price
                                            ? `${item.quantity} × ${formatTicketMoney(
                                                baseUnitPrice,
                                            )}`
                                            : `${item.quantity} pza`}
                                    </span>

                                    {visibility.unit_price && <span className="shrink-0 text-[11px]">
                                        {formatTicketMoney(
                                            baseUnitPrice * item.quantity,
                                        )}
                                    </span>}
                                </div>

                                {modifiers.map((modifier, modifierIndex) => (
                                    <div key={`${modifier.group_name}-${modifier.option_name}-${modifierIndex}`} className="mt-1 flex items-start justify-between gap-3 pl-2 text-[10px] text-muted-foreground">
                                        <span className="min-w-0 break-words">
                                            + {modifier.group_name ? `${modifier.group_name} · ` : ''}{modifier.option_name}
                                        </span>
                                        {visibility.unit_price && <span className="shrink-0">
                                            +{formatTicketMoney(modifier.price_adjustment * item.quantity)}
                                        </span>}
                                    </div>
                                ))}

                                {(modifiers.length > 0 || !visibility.unit_price) && <div className={modifiers.length ? 'mt-1.5 flex items-start justify-between gap-3 border-t border-dotted pt-1.5' : 'mt-1 flex items-start justify-end'}>
                                    {modifiers.length > 0 && <span className="text-[10px] text-muted-foreground">Total producto</span>}
                                    <span className="shrink-0 font-medium">
                                        {formatTicketMoney(item.line_total)}
                                    </span>
                                </div>}
                            </div>
                            )
                        },
                    )}
                </div>

                <Divider />

                <div className="space-y-1">
                    <TicketRow
                        label="Subtotal"
                        value={formatTicketMoney(
                            sale.subtotal,
                        )}
                    />

                    {visibility.tax_breakdown && (
                        <TicketRow
                            label="Impuestos incluidos"
                            value={formatTicketMoney(
                                sale.tax_total,
                            )}
                        />
                    )}

                    <div className="mt-3 border-t pt-3">
                        <div className="flex items-center justify-between gap-4 text-sm font-bold">
                            <span>
                                TOTAL
                            </span>

                            <span>
                                {formatTicketMoney(
                                    sale.total,
                                )}
                            </span>
                        </div>
                    </div>
                </div>

                {hasPaymentInfo && (
                    <>
                        <Divider />

                        <div className="space-y-1">
                            {visibility.payment_method && (
                                <TicketRow
                                    label="Pago"
                                    value={ticketPaymentMethodLabel(
                                        sale.payment_method,
                                    )}
                                />
                            )}

                            {sale.payment_method ===
                                'cash' &&
                                visibility.cash_received &&
                                sale.amount_received !==
                                null && (
                                    <TicketRow
                                        label="Recibido"
                                        value={formatTicketMoney(
                                            sale.amount_received,
                                        )}
                                    />
                                )}

                            {sale.payment_method ===
                                'cash' &&
                                visibility.change &&
                                sale.change_amount !==
                                null && (
                                    <TicketRow
                                        label="Cambio"
                                        value={formatTicketMoney(
                                            sale.change_amount,
                                        )}
                                    />
                                )}
                        </div>
                    </>
                )}

                {settings.invoice.show_qr &&
                    settings.invoice.url && (
                        <>
                            <Divider />

                            <div className="flex flex-col items-center text-center">
                                <QRCodeSVG
                                    value={settings.invoice.url}
                                    size={116}
                                    level="M"
                                    marginSize={1}
                                />

                                <p className="mt-2 max-w-[15rem] text-[10px] leading-4">
                                    {settings.invoice.qr_text ||
                                        'Escanea para solicitar tu factura.'}
                                </p>

                                {settings.invoice.access_code && (
                                    <div className="mt-3">
                                        <p className="text-[8px] text-muted-foreground">
                                            Código de facturación
                                        </p>

                                        <p className="mt-0.5 font-mono text-[9px] font-bold tracking-wider">
                                            {settings.invoice.access_code}
                                        </p>
                                    </div>
                                )}

                                {settings.invoice.entry_url && (
                                    <div className="mt-2 max-w-[15rem] text-[8px] leading-4">
                                        <p>
                                            También puedes ingresar en:
                                        </p>

                                        <p className="break-all font-medium">
                                            {settings.invoice.entry_url}
                                        </p>
                                    </div>
                                )}
                            </div>
                        </>
                    )}

                {settings.additional_text && (
                    <>
                        <Divider />

                        <p className="whitespace-pre-line text-center text-[11px]">
                            {settings.additional_text}
                        </p>
                    </>
                )}

                {settings.footer_text && (
                    <>
                        <Divider />

                        <p className="whitespace-pre-line text-center text-[11px] font-medium">
                            {settings.footer_text}
                        </p>
                    </>
                )}
            </div>
        </div>
    )
}

function formatBranchAddress(branch: TicketDocument['branch']) {
    if (!branch) return ''
    const street = [branch.street, branch.exterior_number, branch.interior_number ? `Int. ${branch.interior_number}` : null].filter(Boolean).join(' ')
    const area = [branch.neighborhood, branch.city, branch.postal_code ? `CP ${branch.postal_code}` : null].filter(Boolean).join(', ')
    return [street, area].filter(Boolean).join(' · ')
}

function TicketRow({
    label,
    value,
}: {
    label: string
    value: string
}) {
    return (
        <div className="flex items-start justify-between gap-4">
            <span>{label}</span>

            <span className="break-words text-right">
                {value}
            </span>
        </div>
    )
}

function Divider() {
    return (
        <div className="my-4 border-t border-dashed" />
    )
}
