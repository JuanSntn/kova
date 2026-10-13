import {
    useEffect,
} from 'react'

import {
    CheckCircle2Icon,
    PrinterIcon,
} from 'lucide-react'

import {
    toast,
} from 'sonner'

import {
    Alert,
    AlertDescription,
} from '@/components/ui/alert'

import {
    Button,
} from '@/components/ui/button'

import {
    Skeleton,
} from '@/components/ui/skeleton'

import {
    useSession,
} from '@/features/auth/session-context'

import {
    TicketReceipt,
} from '@/features/tickets/components/ticket-receipt'

import {
    useOrderTicket,
} from '@/features/tickets/hooks/use-order-ticket'

import {
    printTicket,
} from '@/features/tickets/print-ticket'

import {
    formatTicketMoney,
    ticketPaymentMethodLabel,
} from '@/features/tickets/utils'

import type {
    CheckoutReceipt,
} from '../types'

type CheckoutSuccessProps = {
    receipt:
    CheckoutReceipt

    onNewSale:
    () => void
}

export function CheckoutSuccess({
    receipt,
    onNewSale,
}: CheckoutSuccessProps) {
    const {
        activeTenant,
    } =
        useSession()

    const tenantId =
        activeTenant?.id ??
        ''

    const {
        order,
        payment_method,
    } =
        receipt

    const total =
        Number(
            order.total,
        )

    /*
    |--------------------------------------------------------------------------
    | Ticket
    |--------------------------------------------------------------------------
    */

    const ticketQuery =
        useOrderTicket(
            tenantId,
            order.id,
        )

    /*
    |--------------------------------------------------------------------------
    | Auto print
    |--------------------------------------------------------------------------
    |
    | sessionStorage evita que React StrictMode
    | dispare dos veces la impresión del mismo
    | ticket durante desarrollo.
    |
    */

    useEffect(() => {
        const ticket =
            ticketQuery.data

        if (
            !ticket ||
            !ticket.settings
                .printing
                .auto_print
        ) {
            return
        }

        const storageKey =
            `kova:auto-print:${ticket.sale.id}`

        try {
            if (window.sessionStorage.getItem(storageKey)) return
            window.sessionStorage.setItem(storageKey, '1')
        } catch {
            toast.error('La venta está registrada. Usa Imprimir ticket para imprimir manualmente.')
            return
        }
        void printTicket(ticket).catch(() => {
            try { window.sessionStorage.removeItem(storageKey) } catch { /* Receipt remains saved. */ }
            toast.error('La venta está registrada. No pudimos abrir la impresión automática.')
        })
    }, [
        ticketQuery.data,
    ])

    /*
    |--------------------------------------------------------------------------
    | Manual print
    |--------------------------------------------------------------------------
    */

    async function handlePrint() {
        const ticket =
            ticketQuery.data

        if (!ticket) {
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
        <div className="flex h-full flex-col">
            {/*
            |--------------------------------------------------------------------------
            | Main
            |--------------------------------------------------------------------------
            */}

            <div className="min-h-0 flex-1 overflow-y-auto px-6 py-6">
                {/*
                |--------------------------------------------------------------------------
                | Success
                |--------------------------------------------------------------------------
                */}

                <div className="flex flex-col items-center text-center">
                    <div className="flex size-12 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600">
                        <CheckCircle2Icon className="size-6" />
                    </div>

                    <h2 className="mt-3 text-lg font-semibold">
                        Venta completada
                    </h2>

                    <p className="mt-1 text-3xl font-bold tracking-tight">
                        {formatTicketMoney(
                            total,
                        )}
                    </p>

                    <div className="mt-2 flex flex-wrap items-center justify-center gap-2 text-xs text-muted-foreground">
                        {!!order.inventory_warnings?.length && <div role="status" className="text-sm text-destructive">{order.inventory_warnings.map(message => <p key={message}>{message}</p>)}</div>}
                        {order.folio && (
                            <>
                                <span>
                                    Folio{' '}
                                    {
                                        order.folio
                                    }
                                </span>

                                <span>
                                    ·
                                </span>
                            </>
                        )}

                        <span>
                            {ticketPaymentMethodLabel(
                                payment_method,
                            )}
                        </span>
                    </div>
                </div>

                {/*
                |--------------------------------------------------------------------------
                | Ticket
                |--------------------------------------------------------------------------
                */}

                <div className="mt-6">
                    {ticketQuery.isPending && (
                        <TicketSkeleton />
                    )}

                    {ticketQuery.isError && (
                        <Alert variant="destructive">
                            <AlertDescription>
                                La venta fue registrada correctamente, pero no pudimos cargar el ticket. Puedes volver a intentarlo desde el historial de ventas.
                            </AlertDescription>
                        </Alert>
                    )}

                    {ticketQuery.data && (
                        <TicketReceipt
                            ticket={
                                ticketQuery.data
                            }
                        />
                    )}
                </div>
            </div>

            {/*
            |--------------------------------------------------------------------------
            | Actions
            |--------------------------------------------------------------------------
            */}

            <div className="shrink-0 space-y-2 border-t bg-background px-6 py-4">
                <Button
                    type="button"
                    variant="outline"
                    className="w-full"
                    disabled={
                        !ticketQuery.data
                    }
                    onClick={() =>
                        void handlePrint()
                    }
                >
                    <PrinterIcon className="size-4" />

                    {ticketQuery.isPending
                        ? 'Preparando ticket...'
                        : 'Imprimir ticket'}
                </Button>

                <Button
                    type="button"
                    className="w-full"
                    onClick={
                        onNewSale
                    }
                >
                    Nueva venta
                </Button>
            </div>
        </div>
    )
}

function TicketSkeleton() {
    return (
        <div className="space-y-3 rounded-xl border p-5">
            <div className="flex flex-col items-center gap-2">
                <Skeleton className="h-6 w-28" />

                <Skeleton className="h-3 w-36" />

                <Skeleton className="h-3 w-44" />
            </div>

            <div className="my-4 border-t border-dashed" />

            <Skeleton className="h-4 w-full" />
            <Skeleton className="h-4 w-full" />

            <div className="my-4 border-t border-dashed" />

            <Skeleton className="h-12 w-full" />
            <Skeleton className="h-12 w-full" />

            <div className="my-4 border-t border-dashed" />

            <Skeleton className="h-20 w-full" />
        </div>
    )
}
