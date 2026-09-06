import {
    AlertCircleIcon,
} from 'lucide-react'

import {
    Alert,
    AlertDescription,
} from '@/components/ui/alert'

import {
    PaymentMethodsCard,
} from './payment-methods-card'

import {
    SalesSummaryCards,
} from './sales-summary-cards'

import {
    SalesTrendCard,
} from './sales-trend-card'

import {
    TopProductsCard,
} from './top-products-card'

import type {
    SalesSummary,
} from '../types'

type SalesSummaryTabProps = {
    summary?: SalesSummary
    isLoading: boolean
    isError: boolean
}

export function SalesSummaryTab({
    summary,
    isLoading,
    isError,
}: SalesSummaryTabProps) {
    if (isLoading) {
        return (
            <SalesSummarySkeleton />
        )
    }

    if (isError || !summary) {
        return (
            <Alert variant="destructive">
                <AlertCircleIcon className="size-4" />

                <AlertDescription>
                    No pudimos cargar el resumen de ventas.
                </AlertDescription>
            </Alert>
        )
    }

    return (
        <div className="space-y-5">
            <SalesSummaryCards
                summary={summary}
            />

            <div className="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(280px,1fr)]">
                <SalesTrendCard
                    data={summary.sales_by_day}
                />

                <PaymentMethodsCard
                    methods={
                        summary.payment_methods
                    }
                />
            </div>

            <TopProductsCard
                products={
                    summary.top_products
                }
            />
        </div>
    )
}

function SalesSummarySkeleton() {
    return (
        <div className="space-y-5">
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                {Array.from({
                    length: 4,
                }).map((_, index) => (
                    <div
                        key={index}
                        className="h-32 animate-pulse rounded-xl border bg-muted/30"
                    />
                ))}
            </div>

            <div className="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(280px,1fr)]">
                <div className="h-72 animate-pulse rounded-xl border bg-muted/30" />

                <div className="h-72 animate-pulse rounded-xl border bg-muted/30" />
            </div>

            <div className="h-52 animate-pulse rounded-xl border bg-muted/30" />
        </div>
    )
}