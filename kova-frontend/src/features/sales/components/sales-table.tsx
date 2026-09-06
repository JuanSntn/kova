import {
    ChevronLeftIcon,
    ChevronRightIcon,
} from 'lucide-react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'

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
    return (
        <div className="overflow-hidden rounded-xl border">
            <div className="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>
                                Folio
                            </TableHead>

                            <TableHead>
                                Fecha
                            </TableHead>

                            <TableHead>
                                Método
                            </TableHead>

                            <TableHead>
                                Estado
                            </TableHead>

                            <TableHead className="text-right">
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
                                    className="h-32 text-center text-muted-foreground"
                                >
                                    No encontramos ventas.
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
                                        className="cursor-pointer"
                                        onClick={() =>
                                            onSelect(
                                                sale,
                                            )
                                        }
                                    >
                                        <TableCell className="font-medium">
                                            {
                                                sale.folio
                                            }
                                        </TableCell>

                                        <TableCell>
                                            {formatDateTime(
                                                sale.paid_at,
                                                timezone,
                                            )}
                                        </TableCell>

                                        <TableCell>
                                            {paymentMethodLabel(
                                                sale.payment_method,
                                            )}
                                        </TableCell>

                                        <TableCell>
                                            <Badge variant="secondary">
                                                Pagada
                                            </Badge>
                                        </TableCell>

                                        <TableCell className="text-right font-medium">
                                            {formatMoney(
                                                sale.total,
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ),
                            )
                        )}
                    </TableBody>
                </Table>
            </div>

            <div className="flex items-center justify-between border-t px-4 py-3">
                <p className="text-xs text-muted-foreground">
                    {
                        data.meta
                            .total
                    }{' '}
                    ventas
                </p>

                <div className="flex items-center gap-2">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
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

                    <span className="text-xs text-muted-foreground">
                        Página{' '}
                        {
                            data.meta
                                .current_page
                        }{' '}
                        de{' '}
                        {
                            data.meta
                                .last_page
                        }
                    </span>

                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
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
    )
}