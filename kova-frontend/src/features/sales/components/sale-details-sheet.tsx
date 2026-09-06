import {
    PrinterIcon,
} from 'lucide-react'

import {
    toast,
} from 'sonner'

import {
    Button,
} from '@/components/ui/button'

import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet'

import {
    Skeleton,
} from '@/components/ui/skeleton'

import {
    useOrderTicket,
} from '@/features/tickets/hooks/use-order-ticket'

import {
    printTicket,
} from '@/features/tickets/print-ticket'

import {
    useSaleDetail,
} from '../hooks/use-sale-detail'

import {
    formatDateTime,
    formatMoney,
    paymentMethodLabel,
} from '../utils'

export function SaleDetailsSheet({
    open,
    saleId,
    tenantId,
    timezone,
    onOpenChange,
}: {
    open: boolean
    saleId: string | null
    tenantId: string
    timezone?: string

    onOpenChange: (
        open: boolean,
    ) => void
}) {
    /*
    |--------------------------------------------------------------------------
    | Sale
    |--------------------------------------------------------------------------
    */

    const {
        data: sale,
        isPending,
        isError,
    } = useSaleDetail(
        tenantId,
        saleId,
    )

    /*
    |--------------------------------------------------------------------------
    | Configurable ticket
    |--------------------------------------------------------------------------
    |
    | El ticket NO se reconstruye aquí.
    |
    | Laravel devuelve:
    |
    | - configuración actual del ticket
    | - datos comerciales
    | - snapshots históricos de la venta
    | - configuración de impresión
    |
    */

    const ticketQuery =
        useOrderTicket(
            tenantId,
            saleId,
        )

    /*
    |--------------------------------------------------------------------------
    | Print
    |--------------------------------------------------------------------------
    */

    async function handlePrint() {
        const ticket =
            ticketQuery.data

        if (!ticket) {
            toast.error(
                'El ticket todavía no está disponible.',
            )

            return
        }

        try {
            await printTicket(
                ticket,
            )
        } catch {
            toast.error(
                'No pudimos imprimir el ticket.',
            )
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    return (
        <Sheet
            open={open}
            onOpenChange={
                onOpenChange
            }
        >
            <SheetContent className="flex w-full flex-col overflow-hidden p-0 sm:max-w-lg">
                {isPending ? (
                    <div className="space-y-4 p-6 pt-10">
                        <Skeleton className="h-8 w-40" />

                        <Skeleton className="h-20 w-full" />

                        <Skeleton className="h-40 w-full" />

                        <Skeleton className="h-28 w-full" />
                    </div>
                ) : isError || !sale ? (
                    <div className="p-6 pt-10">
                        <p className="text-sm text-destructive">
                            No pudimos cargar la venta.
                        </p>
                    </div>
                ) : (
                    <>
                        {/*
                        |--------------------------------------------------------------------------
                        | Header
                        |--------------------------------------------------------------------------
                        */}

                        <SheetHeader className="border-b px-6 py-5 text-left">
                            <div className="flex items-start justify-between gap-4 pr-6">
                                <div>
                                    <div className="flex items-center gap-2">
                                        <SheetTitle className="text-lg font-semibold">
                                            {
                                                sale.folio
                                            }
                                        </SheetTitle>

                                        <span className="inline-flex items-center rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-medium text-emerald-600">
                                            Pagada
                                        </span>
                                    </div>

                                    <SheetDescription className="mt-1 text-sm">
                                        {formatDateTime(
                                            sale.paid_at,
                                            timezone,
                                        )}
                                    </SheetDescription>
                                </div>
                            </div>
                        </SheetHeader>

                        {/*
                        |--------------------------------------------------------------------------
                        | Content
                        |--------------------------------------------------------------------------
                        */}

                        <div className="flex-1 overflow-y-auto">
                            <div className="space-y-6 p-6">
                                {/*
                                |--------------------------------------------------------------------------
                                | Products
                                |--------------------------------------------------------------------------
                                */}

                                <section>
                                    <div className="mb-3 flex items-center justify-between">
                                        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                            Productos
                                        </p>

                                        <span className="text-xs text-muted-foreground">
                                            {sale.items.reduce(
                                                (
                                                    total,
                                                    item,
                                                ) =>
                                                    total +
                                                    item.quantity,
                                                0,
                                            )}{' '}
                                            artículos
                                        </span>
                                    </div>

                                    <div className="overflow-hidden rounded-xl border">
                                        {sale.items.map(
                                            (
                                                item,
                                                index,
                                            ) => (
                                                <div
                                                    key={`${item.name}-${index}`}
                                                    className={[
                                                        'flex items-start justify-between gap-4 px-4 py-3.5',

                                                        index !==
                                                            sale.items.length -
                                                            1
                                                            ? 'border-b'
                                                            : '',
                                                    ].join(
                                                        ' ',
                                                    )}
                                                >
                                                    <div className="min-w-0">
                                                        <p className="truncate text-sm font-medium">
                                                            {
                                                                item.name
                                                            }
                                                        </p>

                                                        <p className="mt-1 text-xs text-muted-foreground">
                                                            {
                                                                item.quantity
                                                            }{' '}
                                                            ×{' '}
                                                            {formatMoney(
                                                                item.unit_price,
                                                            )}
                                                        </p>
                                                    </div>

                                                    <p className="shrink-0 text-sm font-semibold">
                                                        {formatMoney(
                                                            item.line_total,
                                                        )}
                                                    </p>
                                                </div>
                                            ),
                                        )}
                                    </div>
                                </section>

                                {/*
                                |--------------------------------------------------------------------------
                                | Totals
                                |--------------------------------------------------------------------------
                                */}

                                <section>
                                    <p className="mb-3 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                        Resumen
                                    </p>

                                    <div className="rounded-xl border p-4">
                                        <div className="space-y-2.5">
                                            <AmountRow
                                                label="Subtotal"
                                                value={
                                                    sale.subtotal
                                                }
                                            />

                                            <AmountRow
                                                label="Impuestos incluidos"
                                                value={
                                                    sale.tax_total
                                                }
                                            />
                                        </div>

                                        <div className="my-4 border-t" />

                                        <div className="flex items-center justify-between">
                                            <span className="text-sm font-semibold">
                                                Total
                                            </span>

                                            <span className="text-lg font-semibold tracking-tight">
                                                {formatMoney(
                                                    sale.total,
                                                )}
                                            </span>
                                        </div>
                                    </div>
                                </section>

                                {/*
                                |--------------------------------------------------------------------------
                                | Payment
                                |--------------------------------------------------------------------------
                                */}

                                <section>
                                    <p className="mb-3 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                        Pago
                                    </p>

                                    <div className="rounded-xl border p-4">
                                        <div className="space-y-2.5">
                                            <DetailRow
                                                label="Método"
                                                value={paymentMethodLabel(
                                                    sale.payment_method,
                                                )}
                                            />

                                            {sale.amount_received !==
                                                null && (
                                                    <DetailRow
                                                        label="Recibido"
                                                        value={formatMoney(
                                                            sale.amount_received,
                                                        )}
                                                    />
                                                )}

                                            {sale.change_amount !==
                                                null && (
                                                    <DetailRow
                                                        label="Cambio"
                                                        value={formatMoney(
                                                            sale.change_amount,
                                                        )}
                                                    />
                                                )}
                                        </div>
                                    </div>
                                </section>
                            </div>
                        </div>

                        {/*
                        |--------------------------------------------------------------------------
                        | Footer
                        |--------------------------------------------------------------------------
                        */}

                        <div className="border-t bg-background p-4">
                            <Button
                                size="lg"
                                className="w-full"
                                disabled={
                                    ticketQuery.isPending ||
                                    !ticketQuery.data
                                }
                                onClick={() =>
                                    void handlePrint()
                                }
                            >
                                <PrinterIcon className="size-4" />

                                {ticketQuery.isPending
                                    ? 'Preparando ticket...'
                                    : 'Reimprimir ticket'}
                            </Button>

                            {ticketQuery.isError ? (
                                <p className="mt-2 text-center text-[11px] text-destructive">
                                    No pudimos preparar el ticket para impresión.
                                </p>
                            ) : (
                                <p className="mt-2 text-center text-[11px] text-muted-foreground">
                                    Se utilizará la configuración actual de impresión del ticket.
                                </p>
                            )}
                        </div>
                    </>
                )}
            </SheetContent>
        </Sheet>
    )
}

function AmountRow({
    label,
    value,
}: {
    label: string
    value: number
}) {
    return (
        <div className="flex items-center justify-between gap-4 text-sm">
            <span className="text-muted-foreground">
                {label}
            </span>

            <span className="font-medium">
                {formatMoney(
                    value,
                )}
            </span>
        </div>
    )
}

function DetailRow({
    label,
    value,
}: {
    label: string
    value: string
}) {
    return (
        <div className="flex items-center justify-between gap-4 text-sm">
            <span className="text-muted-foreground">
                {label}
            </span>

            <span className="font-medium">
                {value}
            </span>
        </div>
    )
}