import {
    AlertCircleIcon,
} from 'lucide-react'

import {
    Alert,
    AlertDescription,
} from '@/components/ui/alert'

import {
    SalesFiltersBar,
} from './sales-filters'

import {
    SalesTable,
} from './sales-table'

import type {
    SaleListItem,
    SalesFilters,
    SalesHistoryResponse,
} from '../types'

type SalesHistoryTabProps = {
    filters: SalesFilters

    data?: SalesHistoryResponse

    timezone?: string

    isLoading: boolean
    isError: boolean

    onFiltersChange: (
        filters: SalesFilters,
    ) => void

    onSelectSale: (
        sale: SaleListItem,
    ) => void

    onPageChange: (
        page: number,
    ) => void
}

export function SalesHistoryTab({
    filters,
    data,
    timezone,
    isLoading,
    isError,
    onFiltersChange,
    onSelectSale,
    onPageChange,
}: SalesHistoryTabProps) {
    return (
        <div className="space-y-4">
            {isError && (
                <Alert variant="destructive">
                    <AlertCircleIcon className="size-4" />

                    <AlertDescription>
                        No pudimos cargar el historial de ventas.
                    </AlertDescription>
                </Alert>
            )}

            <SalesFiltersBar
                filters={filters}
                onChange={onFiltersChange}
            />

            {isLoading &&
                !data && (
                    <SalesHistorySkeleton />
                )}

            {data && (
                <SalesTable
                    data={data}
                    timezone={timezone}
                    onSelect={onSelectSale}
                    onPageChange={
                        onPageChange
                    }
                />
            )}
        </div>
    )
}

function SalesHistorySkeleton() {
    return (
        <div className="overflow-hidden rounded-xl border bg-card">
            <div className="border-b bg-muted/20 px-5 py-3">
                <div className="h-3 w-72 animate-pulse rounded bg-muted" />
            </div>

            <div>
                {Array.from({
                    length: 6,
                }).map((_, index) => (
                    <div
                        key={index}
                        className="flex h-14 items-center gap-6 border-b px-5 last:border-b-0"
                    >
                        <div className="h-3 w-24 animate-pulse rounded bg-muted" />
                        <div className="h-3 w-40 animate-pulse rounded bg-muted" />
                        <div className="h-3 w-20 animate-pulse rounded bg-muted" />
                        <div className="h-5 w-16 animate-pulse rounded-full bg-muted" />
                        <div className="ml-auto h-3 w-20 animate-pulse rounded bg-muted" />
                    </div>
                ))}
            </div>
        </div>
    )
}