import {
    SearchIcon,
    XIcon,
} from 'lucide-react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'

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

    return (
        <div className="flex flex-col gap-3 lg:flex-row">
            <div className="relative flex-1">
                <SearchIcon className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />

                <Input
                    value={
                        filters.search ??
                        ''
                    }
                    placeholder="Buscar folio..."
                    className="pl-9"
                    onChange={(event) =>
                        onChange({
                            ...filters,
                            search:
                                event.target
                                    .value,
                            page: 1,
                        })
                    }
                />
            </div>

            <Input
                type="date"
                value={
                    filters.date_from ??
                    ''
                }
                onChange={(event) =>
                    onChange({
                        ...filters,
                        date_from:
                            event.target
                                .value ||
                            undefined,
                        page: 1,
                    })
                }
                className="lg:w-44"
            />

            <Input
                type="date"
                value={
                    filters.date_to ??
                    ''
                }
                onChange={(event) =>
                    onChange({
                        ...filters,
                        date_to:
                            event.target
                                .value ||
                            undefined,
                        page: 1,
                    })
                }
                className="lg:w-44"
            />

            <select
                value={
                    filters.payment_method ??
                    ''
                }
                onChange={(event) =>
                    onChange({
                        ...filters,
                        payment_method:
                            (
                                event.target
                                    .value ||
                                undefined
                            ) as SalesFilters['payment_method'],

                        page: 1,
                    })
                }
                className="h-9 rounded-md border bg-background px-3 text-sm lg:w-44"
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

            <Button
                type="button"
                variant="outline"
                onClick={reset}
            >
                <XIcon className="size-4" />
                Limpiar
            </Button>
        </div>
    )
}