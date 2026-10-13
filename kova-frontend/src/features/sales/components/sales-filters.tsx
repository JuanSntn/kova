import {
    SearchIcon,
    XIcon,
} from 'lucide-react'

import {
    Button,
} from '@/components/ui/button'

import {
    Input,
} from '@/components/ui/input'

import type {
    SalesFilters,
} from '../types'

export function SalesFiltersBar({
    filters,
    onChange,
}: {
    filters: SalesFilters

    onChange: (
        filters: SalesFilters,
    ) => void
}) {
    function reset() {
        onChange({
            page: 1,
            per_page:
                filters.per_page,
        })
    }

    const hasFilters =
        Boolean(
            filters.search ||
            filters.date_from ||
            filters.date_to ||
            filters.payment_method,
        )

    return (
        <div className="rounded-xl border bg-card p-2">
            <div className="flex flex-col gap-2 xl:flex-row xl:items-center">
                {/*
                |--------------------------------------------------------------------------
                | Search
                |--------------------------------------------------------------------------
                */}

                <div className="relative min-w-0 flex-1">
                    <SearchIcon className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />

                    <Input
                        value={
                            filters.search ??
                            ''
                        }
                        placeholder="Buscar por folio..."
                        className="h-9 border-transparent bg-muted/40 pl-9 shadow-none focus-visible:border-input focus-visible:bg-background"
                        onChange={(
                            event,
                        ) =>
                            onChange({
                                ...filters,

                                search:
                                    event
                                        .target
                                        .value,

                                page: 1,
                            })
                        }
                    />
                </div>

                {/*
                |--------------------------------------------------------------------------
                | Filters
                |--------------------------------------------------------------------------
                */}

                <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                    <Input
                        type="date"
                        aria-label="Fecha inicial"
                        value={
                            filters.date_from ??
                            ''
                        }
                        onChange={(
                            event,
                        ) =>
                            onChange({
                                ...filters,

                                date_from:
                                    event
                                        .target
                                        .value ||
                                    undefined,

                                page: 1,
                            })
                        }
                        className="h-9 bg-background shadow-none sm:w-40"
                    />

                    <Input
                        type="date"
                        aria-label="Fecha final"
                        value={
                            filters.date_to ??
                            ''
                        }
                        onChange={(
                            event,
                        ) =>
                            onChange({
                                ...filters,

                                date_to:
                                    event
                                        .target
                                        .value ||
                                    undefined,

                                page: 1,
                            })
                        }
                        className="h-9 bg-background shadow-none sm:w-40"
                    />

                    <select
                        aria-label="Método de pago"
                        value={
                            filters.payment_method ??
                            ''
                        }
                        onChange={(
                            event,
                        ) =>
                            onChange({
                                ...filters,

                                payment_method:
                                    (
                                        event
                                            .target
                                            .value ||
                                        undefined
                                    ) as SalesFilters['payment_method'],

                                page: 1,
                            })
                        }
                        className="h-9 rounded-md border border-input bg-background px-3 text-sm text-foreground shadow-none outline-none transition-colors focus:border-ring focus:ring-2 focus:ring-ring/20 sm:w-44"
                    >
                        <option value="">
                            Todos los métodos
                        </option>

                        <option value="cash">
                            Efectivo
                        </option>

                        <option value="card">
                            Tarjeta
                        </option>

                        <option value="transfer">
                            Transferencia
                        </option>
                    </select>

                    {hasFilters && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-9 px-3 text-muted-foreground hover:text-foreground"
                            onClick={
                                reset
                            }
                        >
                            <XIcon className="size-4" />

                            Limpiar
                        </Button>
                    )}
                </div>
            </div>
        </div>
    )
}