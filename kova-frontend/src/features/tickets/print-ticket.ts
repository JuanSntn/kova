import type {
    TicketDocument,
} from './types'

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

        /*
         * Dejamos que el navegador
         * termine layout/fuentes/imágenes.
         */
        await delay(
            100,
        )

        printWindow.focus()
        printWindow.print()
    } finally {
        /*
         * print() normalmente bloquea
         * mientras está abierto el diálogo.
         */
        window.setTimeout(
            () => {
                iframe.remove()
            },
            1000,
        )
    }
}

function buildTicketHtml(
    ticket:
        TicketDocument,
): string {
    const paperWidth =
        ticket.settings
            .printing
            .paper_width_mm ===
            58
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
        Array.from(
            {
                length:
                    copies,
            },
        )
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
        ticket.sale.folio ??
        '',
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

                        margin:
                            0 auto
                            8px;
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

                        border-top:
                            1px dashed
                            #000;
                    }

                    .row {
                        display: flex;

                        align-items:
                            flex-start;

                        justify-content:
                            space-between;

                        gap: 8px;

                        margin: 3px 0;
                    }

                    .row-value {
                        text-align: right;
                    }

                    .item {
                        padding:
                            4px 0;
                    }

                    .item-name {
                        font-weight: 600;
                    }

                    .item-detail {
                        display: flex;

                        justify-content:
                            space-between;

                        gap: 8px;

                        margin-top:
                            2px;
                    }

                    .item-total {
                        flex-shrink: 0;

                        font-weight: 600;
                    }

                    .total {
                        margin-top: 8px;
                        padding-top: 8px;

                        border-top:
                            1px solid #000;

                        font-size: 14px;
                        font-weight: 700;
                    }

                    .footer {
                        text-align: center;
                    }

                    img {
                        print-color-adjust:
                            exact;

                        -webkit-print-color-adjust:
                            exact;
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
    ticket:
        TicketDocument,
): string {
    const {
        business,
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
                item => `
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
                                item.unit_price,
                            ),
                        )}`
                        : `${item.quantity} pza`
                    }
                            </span>

                            <span class="item-total">
                                ${escapeHtml(
                        formatTicketMoney(
                            item.line_total,
                        ),
                    )}
                            </span>
                        </div>
                    </div>
                `,
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
        paymentRows.trim() !==
        ''

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
    document:
        Document,
): Promise<void> {
    const images =
        Array.from(
            document.images,
        )

    if (
        images.length ===
        0
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

    /*
     * Nunca bloquear impresión
     * indefinidamente por un logo.
     */
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