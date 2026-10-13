import { createElement } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { QRCodeSVG } from 'qrcode.react'

import type { TicketDocument } from './types'

import {
    formatTicketDateTime,
    formatTicketMoney,
    ticketOrderTypeLabel,
    ticketPaymentMethodLabel,
} from './utils'

export async function printTicket(
    ticket: TicketDocument,
): Promise<void> {
    const iframe =
        document.createElement(
            'iframe',
        )

    iframe.setAttribute(
        'aria-hidden',
        'true',
    )

    iframe.style.position =
        'fixed'

    iframe.style.right =
        '0'

    iframe.style.bottom =
        '0'

    iframe.style.width =
        '0'

    iframe.style.height =
        '0'

    iframe.style.border =
        '0'

    iframe.style.visibility =
        'hidden'

    document.body.appendChild(
        iframe,
    )

    const printWindow =
        iframe.contentWindow

    const printDocument =
        iframe.contentDocument

    if (
        !printWindow ||
        !printDocument
    ) {
        iframe.remove()

        throw new Error(
            'No se pudo crear el documento de impresión.',
        )
    }

    try {
        printDocument.open()

        printDocument.write(
            buildTicketHtml(
                ticket,
            ),
        )

        printDocument.close()

        await waitForImages(
            printDocument,
        )

        await delay(
            100,
        )

        printWindow.focus()
        printWindow.print()
    } finally {
        window.setTimeout(
            () => {
                iframe.remove()
            },
            1000,
        )
    }
}

function buildTicketHtml(
    ticket: TicketDocument,
): string {
    const paperWidth =
        ticket.settings
            .printing
            .paper_width_mm === 58
            ? 58
            : 80

    const contentWidth =
        paperWidth === 58
            ? 50
            : 72

    const copies =
        Math.max(
            1,
            Math.min(
                3,
                ticket.settings
                    .printing
                    .copies,
            ),
        )

    const ticketMarkup =
        renderTicket(
            ticket,
        )

    const copiesMarkup =
        Array.from({
            length: copies,
        })
            .map(
                () => `
                    <section class="ticket-copy">
                        ${ticketMarkup}
                    </section>
                `,
            )
            .join('')

    return `
        <!doctype html>

        <html lang="es">
            <head>
                <meta charset="UTF-8" />

                <meta
                    name="viewport"
                    content="width=device-width, initial-scale=1"
                />

                <title>
                    Ticket ${escapeHtml(
        ticket.sale.folio ?? '',
    )}
                </title>

                <style>
                    @page {
                        size: ${paperWidth}mm auto;
                        margin: 4mm;
                    }

                    * {
                        box-sizing: border-box;
                    }

                    html,
                    body {
                        margin: 0;
                        padding: 0;
                        color: #000;
                        background: #fff;
                        font-family:
                            Arial,
                            Helvetica,
                            sans-serif;
                    }

                    body {
                        width: ${contentWidth}mm;
                        margin: 0 auto;
                        font-size: 11px;
                        line-height: 1.4;
                    }

                    .ticket-copy {
                        width: 100%;
                    }

                    .ticket-copy + .ticket-copy {
                        break-before: page;
                        page-break-before: always;
                    }

                    .center {
                        text-align: center;
                    }

                    .business-logo {
                        display: block;
                        max-width: 34mm;
                        max-height: 16mm;
                        margin: 0 auto 8px;
                    }

                    .business-name {
                        margin: 0;
                        font-size: 15px;
                        font-weight: 700;
                    }

                    .small {
                        margin: 2px 0;
                        font-size: 10px;
                    }

                    .multiline {
                        white-space: normal;
                    }

                    .separator {
                        margin: 10px 0;
                        border-top: 1px dashed #000;
                    }

                    .row {
                        display: flex;
                        align-items: flex-start;
                        justify-content: space-between;
                        gap: 8px;
                        margin: 3px 0;
                    }

                    .row-value {
                        text-align: right;
                    }

                    .item {
                        padding: 4px 0;
                    }

                    .item-name {
                        font-weight: 600;
                    }

                    .item-detail {
                        display: flex;
                        justify-content: space-between;
                        gap: 8px;
                        margin-top: 2px;
                    }

                    .item-total {
                        flex-shrink: 0;
                        font-weight: 600;
                    }

                    .modifier-detail {
                        display: flex;
                        align-items: flex-start;
                        justify-content: space-between;
                        gap: 8px;
                        margin-top: 3px;
                        padding-left: 6px;
                        font-size: 9px;
                    }

                    .modifier-name {
                        min-width: 0;
                        overflow-wrap: anywhere;
                    }

                    .modifier-price {
                        flex-shrink: 0;
                    }

                    .item-line-total {
                        display: flex;
                        justify-content: space-between;
                        gap: 8px;
                        margin-top: 5px;
                        padding-top: 4px;
                        border-top: 1px dotted #000;
                        font-size: 9px;
                    }

                    .total {
                        margin-top: 8px;
                        padding-top: 8px;
                        border-top: 1px solid #000;
                        font-size: 14px;
                        font-weight: 700;
                    }

                    .footer {
                        text-align: center;
                    }

                    .invoice-qr {
                        text-align: center;
                    }

                    .invoice-qr svg {
                        display: block;
                        width: 28mm;
                        height: 28mm;
                        margin: 0 auto;
                    }

                    .invoice-qr-text {
                        max-width: 44mm;
                        margin: 6px auto 0;
                        font-size: 9px;
                        line-height: 1.35;
                        text-align: center;
                    }

                    .invoice-access {
                        margin-top: 8px;
                        text-align: center;
                    }

                    .invoice-access-label {
                        margin: 0;
                        font-size: 8px;
                        line-height: 1.3;
                    }

                    .invoice-access-code {
                        margin: 2px 0 0;
                        font-family:
                            "Courier New",
                            monospace;
                        font-size: 8px;
                        font-weight: 700;
                        letter-spacing: 0.5px;
                    }

                    .invoice-entry {
                        margin-top: 7px;
                        font-size: 8px;
                        line-height: 1.35;
                        text-align: center;
                    }

                    .invoice-entry p {
                        margin: 0;
                    }

                    .invoice-entry-url {
                        margin-top: 2px !important;
                        font-weight: 600;
                        word-break: break-all;
                    }

                    img {
                        print-color-adjust: exact;
                        -webkit-print-color-adjust: exact;
                    }
                </style>
            </head>

            <body>
                ${copiesMarkup}
            </body>
        </html>
    `
}

function renderTicket(
    ticket: TicketDocument,
): string {
    const {
        business,
        branch,
        sale,
        settings,
        timezone,
    } = ticket

    const visibility =
        settings.visibility

    const businessBlock = [
        visibility.logo &&
            business.logo_url
            ? `
                <img
                    class="business-logo"
                    src="${escapeHtml(
                business.logo_url,
            )}"
                    alt=""
                />
            `
            : '',

        visibility.business_name
            ? `
                <p class="business-name">
                    ${escapeHtml(
                business.name,
            )}
                </p>
            `
            : '',

        visibility.branch_name && branch
            ? `<p class="small"><strong>Sucursal ${escapeHtml(branch.name)}</strong></p>`
            : '',

        visibility.address && branch
            ? `<p class="small">${escapeHtml([
                [branch.street, branch.exterior_number, branch.interior_number ? `Int. ${branch.interior_number}` : null].filter(Boolean).join(' '),
                [branch.neighborhood, branch.city, branch.postal_code ? `CP ${branch.postal_code}` : null].filter(Boolean).join(', '),
            ].filter(Boolean).join(' · '))}</p>`
            : '',

        visibility.phone &&
            business.phone
            ? `
                <p class="small">
                    ${escapeHtml(
                business.phone,
            )}
                </p>
            `
            : '',

        visibility.email &&
            business.public_email
            ? `
                <p class="small">
                    ${escapeHtml(
                business.public_email,
            )}
                </p>
            `
            : '',

        settings.header_text
            ? `
                <p class="small multiline">
                    ${multiline(
                settings.header_text,
            )}
                </p>
            `
            : '',
    ].join('')

    const saleRows = [
        visibility.folio &&
            sale.folio
            ? row(
                'Folio',
                sale.folio,
            )
            : '',

        visibility.datetime &&
            sale.paid_at
            ? row(
                'Fecha',
                formatTicketDateTime(
                    sale.paid_at,
                    timezone,
                ),
            )
            : '',

        visibility.cashier &&
            sale.cashier
            ? row(
                'Cajero',
                sale.cashier,
            )
            : '',

        visibility.order_type
            ? row(
                'Orden',
                ticketOrderTypeLabel(
                    sale.order_type,
                ),
            )
            : '',
    ].join('')

    const items =
        sale.items
            .map(
                item => {
                    const modifiers = item.modifiers ?? []
                    const baseUnitPrice = item.base_unit_price ?? item.unit_price

                    return `
                    <div class="item">
                        <div class="item-name">
                            ${escapeHtml(
                    item.name,
                )}
                        </div>

                        <div class="item-detail">
                            <span>
                                ${visibility.unit_price
                        ? `${item.quantity} × ${escapeHtml(
                            formatTicketMoney(
                                baseUnitPrice,
                            ),
                        )}`
                        : `${item.quantity} pza`
                    }
                            </span>

                            ${visibility.unit_price ? `<span class="item-total">
                                ${escapeHtml(
                    formatTicketMoney(
                        baseUnitPrice * item.quantity,
                    ),
                )}
                            </span>` : ''}
                        </div>

                        ${modifiers.map(modifier => `
                            <div class="modifier-detail">
                                <span class="modifier-name">
                                    + ${escapeHtml(modifier.group_name ? `${modifier.group_name} · ${modifier.option_name}` : modifier.option_name)}
                                </span>
                                ${visibility.unit_price ? `<span class="modifier-price">+${escapeHtml(formatTicketMoney(modifier.price_adjustment * item.quantity))}</span>` : ''}
                            </div>
                        `).join('')}

                        ${modifiers.length > 0 ? `
                            <div class="item-line-total">
                                <span>Total producto</span>
                                <span class="item-total">${escapeHtml(formatTicketMoney(item.line_total))}</span>
                            </div>
                        ` : !visibility.unit_price ? `<div class="item-detail"><span></span><span class="item-total">${escapeHtml(formatTicketMoney(item.line_total))}</span></div>` : ''}
                    </div>
                `
                },
            )
            .join('')

    const totals = [
        row(
            'Subtotal',
            formatTicketMoney(
                sale.subtotal,
            ),
        ),

        visibility.tax_breakdown
            ? row(
                'Impuestos incluidos',
                formatTicketMoney(
                    sale.tax_total,
                ),
            )
            : '',

        `
            <div class="row total">
                <span>
                    TOTAL
                </span>

                <span class="row-value">
                    ${escapeHtml(
            formatTicketMoney(
                sale.total,
            ),
        )}
                </span>
            </div>
        `,
    ].join('')

    const paymentRows = [
        visibility.payment_method
            ? row(
                'Pago',
                ticketPaymentMethodLabel(
                    sale.payment_method,
                ),
            )
            : '',

        sale.payment_method ===
            'cash' &&
            visibility.cash_received &&
            sale.amount_received !==
            null
            ? row(
                'Recibido',
                formatTicketMoney(
                    sale.amount_received,
                ),
            )
            : '',

        sale.payment_method ===
            'cash' &&
            visibility.change &&
            sale.change_amount !==
            null
            ? row(
                'Cambio',
                formatTicketMoney(
                    sale.change_amount,
                ),
            )
            : '',
    ].join('')

    const hasPayment =
        paymentRows.trim() !== ''

    const invoiceQr =
        renderInvoiceQr(
            ticket,
        )

    return `
        <div class="center">
            ${businessBlock}
        </div>

        <div class="separator"></div>

        ${saleRows}

        <div class="separator"></div>

        ${items}

        <div class="separator"></div>

        ${totals}

        ${hasPayment
            ? `
                    <div class="separator"></div>

                    ${paymentRows}
                `
            : ''
        }

        ${invoiceQr}

        ${settings.additional_text
            ? `
                    <div class="separator"></div>

                    <div class="footer small">
                        ${multiline(
                settings.additional_text,
            )}
                    </div>
                `
            : ''
        }

        ${settings.footer_text
            ? `
                    <div class="separator"></div>

                    <div class="footer">
                        ${multiline(
                settings.footer_text,
            )}
                    </div>
                `
            : ''
        }
    `
}

function renderInvoiceQr(
    ticket: TicketDocument,
): string {
    const invoice =
        ticket.settings.invoice

    if (
        !invoice.show_qr ||
        !invoice.url
    ) {
        return ''
    }

    const qrMarkup =
        renderToStaticMarkup(
            createElement(
                QRCodeSVG,
                {
                    value:
                        invoice.url,

                    size:
                        180,

                    level:
                        'M',

                    marginSize:
                        1,
                },
            ),
        )

    const qrText =
        invoice.qr_text?.trim()
        ||
        'Escanea para solicitar tu factura.'

    return `
        <div class="separator"></div>

        <div class="invoice-qr">
            ${qrMarkup}

            <p class="invoice-qr-text">
                ${escapeHtml(
        qrText,
    )}
            </p>

            ${invoice.access_code
            ? `
                        <div class="invoice-access">
                            <p class="invoice-access-label">
                                Código de facturación
                            </p>

                            <p class="invoice-access-code">
                                ${escapeHtml(
                invoice.access_code,
            )}
                            </p>
                        </div>
                    `
            : ''
        }

            ${invoice.entry_url
            ? `
                        <div class="invoice-entry">
                            <p>
                                También puedes ingresar en:
                            </p>

                            <p class="invoice-entry-url">
                                ${escapeHtml(
                invoice.entry_url,
            )}
                            </p>
                        </div>
                    `
            : ''
        }
        </div>
    `
}

function row(
    label: string,
    value: string,
): string {
    return `
        <div class="row">
            <span>
                ${escapeHtml(
        label,
    )}
            </span>

            <span class="row-value">
                ${escapeHtml(
        value,
    )}
            </span>
        </div>
    `
}

function multiline(
    value: string,
): string {
    return escapeHtml(
        value,
    ).replace(
        /\r?\n/g,
        '<br />',
    )
}

function escapeHtml(
    value:
        | string
        | number,
): string {
    return String(
        value,
    )
        .replace(
            /&/g,
            '&amp;',
        )
        .replace(
            /</g,
            '&lt;',
        )
        .replace(
            />/g,
            '&gt;',
        )
        .replace(
            /"/g,
            '&quot;',
        )
        .replace(
            /'/g,
            '&#039;',
        )
}

async function waitForImages(
    document: Document,
): Promise<void> {
    const images =
        Array.from(
            document.images,
        )

    if (
        images.length === 0
    ) {
        return
    }

    const loading =
        Promise.all(
            images.map(
                image =>
                    new Promise<void>(
                        resolve => {
                            if (
                                image.complete
                            ) {
                                resolve()

                                return
                            }

                            image.onload =
                                () =>
                                    resolve()

                            image.onerror =
                                () =>
                                    resolve()
                        },
                    ),
            ),
        ).then(
            () =>
                undefined,
        )

    await Promise.race([
        loading,
        delay(
            1500,
        ),
    ])
}

function delay(
    milliseconds: number,
): Promise<void> {
    return new Promise(
        resolve => {
            window.setTimeout(
                resolve,
                milliseconds,
            )
        },
    )
}
