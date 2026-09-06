import type {
    TicketDocument,
} from '../types'

import {
    formatTicketDateTime,
    formatTicketMoney,
    ticketOrderTypeLabel,
    ticketPaymentMethodLabel,
} from '../utils'

type TicketReceiptProps = {
    ticket:
    TicketDocument
}

export function TicketReceipt({
    ticket,
}: TicketReceiptProps) {
    const {
        business,
        sale,
        settings,
        timezone,
    } = ticket

    const visibility =
        settings.visibility

    const hasPaymentInfo =
        visibility.payment_method ||
        (
            sale.payment_method ===
            'cash' &&
            (
                visibility.cash_received ||
                visibility.change
            )
        )

    return (
        <div className="overflow-hidden rounded-xl border bg-background">
            <div className="mx-auto w-full max-w-sm px-5 py-6 font-mono text-[12px] leading-relaxed">
                {/*
                |--------------------------------------------------------------------------
                | Business
                |--------------------------------------------------------------------------
                */}

                <div className="text-center">
                    {visibility.logo &&
                        business.logo_url && (
                            <img
                                src={
                                    business.logo_url
                                }
                                alt=""
                                className="mx-auto mb-3 max-h-14 max-w-36 object-contain"
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
                            <p className="mt-1 text-[11px]">
                                {
                                    business.phone
                                }
                            </p>
                        )}

                    {visibility.email &&
                        business.public_email && (
                            <p className="break-all text-[11px]">
                                {
                                    business.public_email
                                }
                            </p>
                        )}

                    {settings.header_text && (
                        <p className="mt-3 whitespace-pre-line text-[11px]">
                            {
                                settings.header_text
                            }
                        </p>
                    )}
                </div>

                <Divider />

                {/*
                |--------------------------------------------------------------------------
                | Sale metadata
                |--------------------------------------------------------------------------
                */}

                <div className="space-y-1">
                    {visibility.folio &&
                        sale.folio && (
                            <TicketRow
                                label="Folio"
                                value={
                                    sale.folio
                                }
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
                                value={
                                    sale.cashier
                                }
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

                {/*
                |--------------------------------------------------------------------------
                | Items
                |--------------------------------------------------------------------------
                */}

                <div className="space-y-3">
                    {sale.items.map(
                        (
                            item,
                            index,
                        ) => (
                            <div
                                key={`${item.name}-${index}`}
                            >
                                <p className="font-semibold">
                                    {
                                        item.name
                                    }
                                </p>

                                <div className="mt-0.5 flex items-start justify-between gap-3">
                                    <span className="text-[11px]">
                                        {visibility.unit_price
                                            ? `${item.quantity} × ${formatTicketMoney(
                                                item.unit_price,
                                            )}`
                                            : `${item.quantity} pza`}
                                    </span>

                                    <span className="shrink-0 font-medium">
                                        {formatTicketMoney(
                                            item.line_total,
                                        )}
                                    </span>
                                </div>
                            </div>
                        ),
                    )}
                </div>

                <Divider />

                {/*
                |--------------------------------------------------------------------------
                | Totals
                |--------------------------------------------------------------------------
                */}

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

                {/*
                |--------------------------------------------------------------------------
                | Payment
                |--------------------------------------------------------------------------
                */}

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

                {/*
                |--------------------------------------------------------------------------
                | Additional text
                |--------------------------------------------------------------------------
                */}

                {settings.additional_text && (
                    <>
                        <Divider />

                        <p className="whitespace-pre-line text-center text-[11px]">
                            {
                                settings.additional_text
                            }
                        </p>
                    </>
                )}

                {/*
                |--------------------------------------------------------------------------
                | Footer
                |--------------------------------------------------------------------------
                */}

                {settings.footer_text && (
                    <>
                        <Divider />

                        <p className="whitespace-pre-line text-center text-[11px] font-medium">
                            {
                                settings.footer_text
                            }
                        </p>
                    </>
                )}
            </div>
        </div>
    )
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
            <span>
                {label}
            </span>

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