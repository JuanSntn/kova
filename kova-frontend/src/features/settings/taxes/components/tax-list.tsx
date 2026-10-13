import {
    Loader2Icon,
} from 'lucide-react'

import {
    Badge,
} from '@/components/ui/badge'
import {
    Button,
} from '@/components/ui/button'

import type {
    Tax,
} from '../types'

type TaxListProps = {
    taxes:
    Tax[]

    defaultTaxId:
    string

    savingId:
    string | null

    onToggleActive: (
        tax: Tax
    ) => void
}

export function TaxList({
    taxes,
    defaultTaxId,
    savingId,
    onToggleActive,
}: TaxListProps) {
    return (
        <section className="space-y-3">
            {/*
            |--------------------------------------------------------------------------
            | Header
            |--------------------------------------------------------------------------
            */}

            <div className="flex items-end justify-between gap-4">
                <div>
                    <h2 className="text-sm font-semibold">
                        Impuestos disponibles
                    </h2>

                    <p className="mt-0.5 text-xs text-muted-foreground">
                        Kova mantiene estos tratamientos para evitar tasas fiscales inválidas.
                    </p>
                </div>
            </div>

            {/*
            |--------------------------------------------------------------------------
            | Empty
            |--------------------------------------------------------------------------
            */}

            {taxes.length ===
                0 ? (
                <div className="rounded-lg border border-dashed px-4 py-8 text-center">
                    <p className="text-sm font-medium">
                        No hay impuestos configurados
                    </p>

                    <p className="mt-1 text-xs text-muted-foreground">
                        No pudimos encontrar los tratamientos fiscales administrados por Kova.
                    </p>
                </div>
            ) : (
                <div className="overflow-hidden rounded-lg border">

                    {/*
                    |--------------------------------------------------------------------------
                    | Desktop header
                    |--------------------------------------------------------------------------
                    */}

                    <div className="hidden grid-cols-[minmax(180px,1fr)_120px_100px_220px] gap-4 border-b bg-muted/30 px-4 py-2 md:grid">
                        <span className="text-xs font-medium text-muted-foreground">
                            Nombre
                        </span>

                        <span className="text-xs font-medium text-muted-foreground">
                            Tipo
                        </span>

                        <span className="text-xs font-medium text-muted-foreground">
                            Tasa
                        </span>

                        <span className="text-right text-xs font-medium text-muted-foreground">
                            Estado
                        </span>
                    </div>

                    {/*
                    |--------------------------------------------------------------------------
                    | Rows
                    |--------------------------------------------------------------------------
                    */}

                    {taxes.map(
                        (tax) => {
                            const isDefault =
                                tax.id ===
                                defaultTaxId

                            const isSaving =
                                savingId ===
                                tax.id

                            return (
                                <div
                                    key={
                                        tax.id
                                    }
                                    className="
                                        grid
                                        gap-3
                                        border-b
                                        px-4
                                        py-3
                                        last:border-b-0

                                        md:grid-cols-[minmax(180px,1fr)_120px_100px_220px]
                                        md:items-center
                                        md:gap-4
                                    "
                                >
                                    {/*
                                     * Name
                                     */}

                                    <div className="flex min-w-0 flex-wrap items-center gap-2">
                                        <span className="truncate text-sm font-medium">
                                            {
                                                tax.name
                                            }
                                        </span>

                                        {isDefault && (
                                            <Badge
                                                variant="secondary"
                                                className="h-5 px-1.5 text-[10px]"
                                            >
                                                Predeterminado
                                            </Badge>
                                        )}
                                    </div>

                                    {/*
                                     * Type
                                     */}

                                    <div className="flex items-center justify-between md:block">
                                        <span className="text-xs text-muted-foreground md:hidden">
                                            Tipo
                                        </span>

                                        <span className="text-sm">
                                            {tax.factor_type ===
                                                'exempt'
                                                ? 'Exento'
                                                : 'Tasa'}
                                        </span>
                                    </div>

                                    {/*
                                     * Rate
                                     */}

                                    <div className="flex items-center justify-between md:block">
                                        <span className="text-xs text-muted-foreground md:hidden">
                                            Tasa
                                        </span>

                                        <span className="text-sm tabular-nums">
                                            {tax.factor_type ===
                                                'exempt'
                                                ? '—'
                                                : formatRate(
                                                    tax.rate,
                                                )}
                                        </span>
                                    </div>

                                    {/*
                                     * Status / action
                                     */}

                                    <div className="flex items-center justify-between gap-3 md:justify-end">
                                        <div className="flex items-center gap-1.5">
                                            <span
                                                className={
                                                    tax.is_active
                                                        ? 'size-1.5 rounded-full bg-emerald-500'
                                                        : 'size-1.5 rounded-full bg-muted-foreground/50'
                                                }
                                            />

                                            <span className="text-xs text-muted-foreground">
                                                {tax.is_active
                                                    ? 'Activo'
                                                    : 'Inactivo'}
                                            </span>
                                        </div>

                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            className="h-8 min-w-20 px-2 text-xs"
                                            disabled={
                                                isSaving ||
                                                isDefault
                                            }
                                            onClick={() =>
                                                onToggleActive(
                                                    tax,
                                                )
                                            }
                                        >
                                            {isSaving ? (
                                                <Loader2Icon className="size-3.5 animate-spin" />
                                            ) : tax.is_active ? (
                                                'Desactivar'
                                            ) : (
                                                'Activar'
                                            )}
                                        </Button>
                                    </div>
                                </div>
                            )
                        },
                    )}
                </div>
            )}
        </section>
    )
}

function formatRate(
    rate:
        | string
        | number,
): string {
    const value =
        Number(rate)

    if (
        !Number.isFinite(
            value,
        )
    ) {
        return '0%'
    }

    return `${value}%`
}
