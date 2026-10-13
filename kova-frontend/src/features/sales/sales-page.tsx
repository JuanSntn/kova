import {
    useState,
} from 'react'

import {
    SectionNav,
    SectionNavButton,
} from '@/components/layout/section-nav'

import {
    useSession,
} from '@/features/auth/session-context'
import { useBranch } from '@/features/branches/branch-context'

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
    const { activeBranchId } = useBranch()

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
            activeBranchId ?? '',
        )

    const historyQuery =
        useSalesHistory(
            tenantId,
            activeBranchId ?? '',
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

            <SectionNav label="Secciones de ventas">
                <SectionNavButton
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
                </SectionNavButton>

                <SectionNavButton
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
                </SectionNavButton>
            </SectionNav>

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
