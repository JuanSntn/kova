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
        <div className="space-y-5">
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
        <div className="overflow-hidden rounded-xl border">
            <div className="space-y-1 p-4">
                {Array.from({
                    length: 6,
                }).map((_, index) => (
                    <div
                        key={index}
                        className="h-12 animate-pulse rounded-lg bg-muted/40"
                    />
                ))}
            </div>
        </div>
    )
}