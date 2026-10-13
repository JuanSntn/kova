import {
    ChevronLeftIcon,
    ChevronRightIcon,
} from 'lucide-react'

import {
    Badge,
} from '@/components/ui/badge'

import {
    Button,
} from '@/components/ui/button'

import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table'

import type {
    SaleListItem,
    SalesHistoryResponse,
} from '../types'

import {
    formatDateTime,
    formatMoney,
    paymentMethodLabel,
} from '../utils'

export function SalesTable({
    data,
    timezone,
    onSelect,
    onPageChange,
}: {
    data: SalesHistoryResponse
    timezone?: string

    onSelect: (
        sale: SaleListItem,
    ) => void

    onPageChange: (
        page: number,
    ) => void
}) {
    const firstItem =
        data.meta.total === 0
            ? 0
            : (
                data.meta.current_page -
                1
            ) *
            data.meta.per_page +
            1

    const lastItem =
        Math.min(
            data.meta.current_page *
            data.meta.per_page,
            data.meta.total,
        )

    return (
        <div className="overflow-hidden rounded-xl border bg-card">
            {/*
            |--------------------------------------------------------------------------
            | Table
            |--------------------------------------------------------------------------
            */}

            <div className="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow className="border-b bg-muted/20 hover:bg-muted/20">
                            <TableHead className="h-10 px-5 text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                                Venta
                            </TableHead>

                            <TableHead className="h-10 text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                                Fecha
                            </TableHead>

                            <TableHead className="h-10 text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                                Método
                            </TableHead>

                            <TableHead className="h-10 text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                                Estado
                            </TableHead>

                            <TableHead className="h-10 pr-5 text-right text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                                Total
                            </TableHead>
                        </TableRow>
                    </TableHeader>

                    <TableBody>
                        {data.data.length ===
                            0 ? (
                            <TableRow>
                                <TableCell
                                    colSpan={
                                        5
                                    }
                                    className="h-40 text-center"
                                >
                                    <div className="mx-auto max-w-xs">
                                        <p className="text-sm font-medium text-foreground">
                                            No encontramos ventas
                                        </p>

                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Prueba con otros filtros o un rango de fechas diferente.
                                        </p>
                                    </div>
                                </TableCell>
                            </TableRow>
                        ) : (
                            data.data.map(
                                (
                                    sale,
                                ) => (
                                    <TableRow
                                        key={
                                            sale.id
                                        }
                                        tabIndex={
                                            0
                                        }
                                        className="group h-14 cursor-pointer border-b transition-colors last:border-b-0 hover:bg-muted/30 focus-visible:bg-muted/30 focus-visible:outline-none"
                                        onClick={() =>
                                            onSelect(
                                                sale,
                                            )
                                        }
                                        onKeyDown={(
                                            event,
                                        ) => {
                                            if (
                                                event.key ===
                                                'Enter' ||
                                                event.key ===
                                                ' '
                                            ) {
                                                event.preventDefault()

                                                onSelect(
                                                    sale,
                                                )
                                            }
                                        }}
                                    >
                                        {/*
                                        |--------------------------------------------------------------------------
                                        | Folio
                                        |--------------------------------------------------------------------------
                                        */}

                                        <TableCell className="px-5">
                                            <div className="flex flex-col">
                                                <span className="text-sm font-medium text-foreground">
                                                    {
                                                        sale.folio
                                                    }
                                                </span>

                                                <span className="mt-0.5 text-[11px] text-muted-foreground">
                                                    Venta
                                                </span>
                                            </div>
                                        </TableCell>

                                        {/*
                                        |--------------------------------------------------------------------------
                                        | Date
                                        |--------------------------------------------------------------------------
                                        */}

                                        <TableCell>
                                            <span className="whitespace-nowrap text-sm text-foreground/80">
                                                {formatDateTime(
                                                    sale.paid_at,
                                                    timezone,
                                                )}
                                            </span>
                                        </TableCell>

                                        {/*
                                        |--------------------------------------------------------------------------
                                        | Payment
                                        |--------------------------------------------------------------------------
                                        */}

                                        <TableCell>
                                            <span className="text-sm text-foreground/80">
                                                {paymentMethodLabel(
                                                    sale.payment_method,
                                                )}
                                            </span>
                                        </TableCell>

                                        {/*
                                        |--------------------------------------------------------------------------
                                        | Status
                                        |--------------------------------------------------------------------------
                                        */}

                                        <TableCell>
                                            <Badge
                                                variant="outline"
                                                className="h-6 gap-1.5 rounded-full border-border bg-background px-2 text-[11px] font-normal text-foreground"
                                            >
                                                <span className="size-1.5 rounded-full bg-emerald-500" />

                                                Pagada
                                            </Badge>
                                        </TableCell>

                                        {/*
                                        |--------------------------------------------------------------------------
                                        | Total
                                        |--------------------------------------------------------------------------
                                        */}

                                        <TableCell className="pr-5 text-right">
                                            <span className="whitespace-nowrap text-sm font-semibold tabular-nums text-foreground">
                                                {formatMoney(
                                                    sale.total,
                                                )}
                                            </span>
                                        </TableCell>
                                    </TableRow>
                                ),
                            )
                        )}
                    </TableBody>
                </Table>
            </div>

            {/*
            |--------------------------------------------------------------------------
            | Pagination
            |--------------------------------------------------------------------------
            */}

            <div className="flex flex-col gap-3 border-t bg-muted/10 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-xs text-muted-foreground">
                    {data.meta.total ===
                        0
                        ? '0 ventas'
                        : `${firstItem}–${lastItem} de ${data.meta.total} ventas`}
                </p>

                <div className="flex items-center gap-3">
                    <span className="text-xs text-muted-foreground">
                        Página{' '}
                        <span className="font-medium text-foreground">
                            {
                                data.meta
                                    .current_page
                            }
                        </span>{' '}
                        de{' '}
                        <span className="font-medium text-foreground">
                            {
                                data.meta
                                    .last_page
                            }
                        </span>
                    </span>

                    <div className="flex items-center gap-1">
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            className="size-8 border"
                            aria-label="Página anterior"
                            disabled={
                                data.meta
                                    .current_page <=
                                1
                            }
                            onClick={() =>
                                onPageChange(
                                    data.meta
                                        .current_page -
                                    1,
                                )
                            }
                        >
                            <ChevronLeftIcon className="size-4" />
                        </Button>

                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            className="size-8 border"
                            aria-label="Página siguiente"
                            disabled={
                                data.meta
                                    .current_page >=
                                data.meta
                                    .last_page
                            }
                            onClick={() =>
                                onPageChange(
                                    data.meta
                                        .current_page +
                                    1,
                                )
                            }
                        >
                            <ChevronRightIcon className="size-4" />
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    )
}