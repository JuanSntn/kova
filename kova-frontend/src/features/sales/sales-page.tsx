import {
    useState,
} from 'react'

import {
    useSession,
} from '@/features/auth/session-context'

import {
    SaleDetailsSheet,
} from './components/sale-details-sheet'

import {
    SalesHistoryTab,
} from './components/sales-history-tab'

import {
    SalesSummaryTab,
} from './components/sales-summary-tab'

import {
    useSalesHistory,
} from './hooks/use-sales-history'

import {
    useSalesSummary,
} from './hooks/use-sales-summary'

import type {
    SalesFilters,
} from './types'

type SalesView =
    | 'summary'
    | 'history'

export function SalesPage() {
    const {
        activeTenant,
    } = useSession()

    const tenantId =
        activeTenant?.id ?? ''

    /*
    |--------------------------------------------------------------------------
    | View
    |--------------------------------------------------------------------------
    */

    const [
        view,
        setView,
    ] =
        useState<SalesView>(
            'summary',
        )

    /*
    |--------------------------------------------------------------------------
    | Selected sale
    |--------------------------------------------------------------------------
    */

    const [
        selectedSaleId,
        setSelectedSaleId,
    ] =
        useState<
            string | null
        >(null)

    /*
    |--------------------------------------------------------------------------
    | History filters
    |--------------------------------------------------------------------------
    */

    const [
        filters,
        setFilters,
    ] =
        useState<SalesFilters>({
            page: 1,
            per_page: 20,
        })

    /*
    |--------------------------------------------------------------------------
    | Queries
    |--------------------------------------------------------------------------
    */

    const summaryQuery =
        useSalesSummary(
            tenantId,
        )

    const historyQuery =
        useSalesHistory(
            tenantId,
            filters,
        )

    /*
    |--------------------------------------------------------------------------
    | Active header
    |--------------------------------------------------------------------------
    */

    const pageHeader =
        view === 'summary'
            ? {
                title:
                    'Resumen de ventas',

                description:
                    'Consulta el desempeño de tu negocio durante el período actual.',
            }
            : {
                title:
                    'Historial de ventas',

                description:
                    'Consulta una venta y vuelve a imprimir su ticket.',
            }

    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    return (
        <>
            {/*
            |--------------------------------------------------------------------------
            | Navigation
            |--------------------------------------------------------------------------
            */}

            <nav
                aria-label="Secciones de ventas"
                className="-mb-px flex gap-5 border-b"
            >
                <SalesNavButton
                    active={
                        view ===
                        'summary'
                    }
                    onClick={() =>
                        setView(
                            'summary',
                        )
                    }
                >
                    Resumen
                </SalesNavButton>

                <SalesNavButton
                    active={
                        view ===
                        'history'
                    }
                    onClick={() =>
                        setView(
                            'history',
                        )
                    }
                >
                    Historial
                </SalesNavButton>
            </nav>

            {/*
            |--------------------------------------------------------------------------
            | Page content
            |--------------------------------------------------------------------------
            */}

            <div className="space-y-5">
                {/*
                |--------------------------------------------------------------------------
                | Header
                |--------------------------------------------------------------------------
                */}

                <div>
                    <h1 className="text-xl font-semibold tracking-tight">
                        {
                            pageHeader.title
                        }
                    </h1>

                    <p className="mt-1 text-sm text-muted-foreground">
                        {
                            pageHeader.description
                        }
                    </p>
                </div>

                {/*
                |--------------------------------------------------------------------------
                | Summary
                |--------------------------------------------------------------------------
                */}

                {view ===
                    'summary' ? (
                    <SalesSummaryTab
                        summary={
                            summaryQuery.data
                        }
                        isLoading={
                            summaryQuery.isPending
                        }
                        isError={
                            summaryQuery.isError
                        }
                    />
                ) : (
                    /*
                    |--------------------------------------------------------------------------
                    | History
                    |--------------------------------------------------------------------------
                    */

                    <SalesHistoryTab
                        filters={
                            filters
                        }
                        data={
                            historyQuery.data
                        }
                        timezone={
                            summaryQuery
                                .data
                                ?.timezone
                        }
                        isLoading={
                            historyQuery.isPending
                        }
                        isError={
                            historyQuery.isError
                        }
                        onFiltersChange={
                            setFilters
                        }
                        onSelectSale={(
                            sale,
                        ) =>
                            setSelectedSaleId(
                                sale.id,
                            )
                        }
                        onPageChange={(
                            page,
                        ) =>
                            setFilters(
                                (
                                    current,
                                ) => ({
                                    ...current,
                                    page,
                                }),
                            )
                        }
                    />
                )}
            </div>

            {/*
            |--------------------------------------------------------------------------
            | Sale details
            |--------------------------------------------------------------------------
            */}

            <SaleDetailsSheet
                open={
                    selectedSaleId !==
                    null
                }
                saleId={
                    selectedSaleId
                }
                tenantId={
                    tenantId
                }
                timezone={
                    summaryQuery
                        .data
                        ?.timezone
                }
                onOpenChange={(
                    open,
                ) => {
                    if (!open) {
                        setSelectedSaleId(
                            null,
                        )
                    }
                }}
            />
        </>
    )
}

/*
|--------------------------------------------------------------------------
| Navigation button
|--------------------------------------------------------------------------
*/

function SalesNavButton({
    active,
    children,
    onClick,
}: {
    active: boolean
    children: React.ReactNode
    onClick: () => void
}) {
    return (
        <button
            type="button"
            aria-current={
                active
                    ? 'page'
                    : undefined
            }
            className={[
                'border-b-2 px-0 py-2.5 text-xs font-medium transition-colors',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',

                active
                    ? 'border-primary text-foreground'
                    : 'border-transparent text-muted-foreground hover:text-foreground',
            ].join(' ')}
            onClick={
                onClick
            }
        >
            {children}
        </button>
    )
}