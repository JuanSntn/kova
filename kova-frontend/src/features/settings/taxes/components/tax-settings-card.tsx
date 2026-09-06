import {
    Label,
} from '@/components/ui/label'
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select'

import type {
    Tax,
    TaxConfig,
} from '../types'

type TaxSettingsCardProps = {
    taxes:
    Tax[]

    config:
    TaxConfig

    saving:
    boolean

    onDefaultTaxChange: (
        taxId: string
    ) => void
}

const EXAMPLE_TOTAL =
    150

export function TaxSettingsCard({
    taxes,
    config,
    saving,
    onDefaultTaxChange,
}: TaxSettingsCardProps) {
    /*
    |--------------------------------------------------------------------------
    | Available taxes
    |--------------------------------------------------------------------------
    */

    const activeTaxes =
        taxes.filter(
            (tax) =>
                tax.is_active,
        )

    /*
    |--------------------------------------------------------------------------
    | Example
    |--------------------------------------------------------------------------
    */

    const example =
        calculateExample(
            EXAMPLE_TOTAL,
            config.default_tax,
        )

    return (
        <section className="space-y-4 border-b pb-5">
            {/*
            |--------------------------------------------------------------------------
            | Heading
            |--------------------------------------------------------------------------
            */}

            <div>
                <h2 className="text-sm font-semibold">
                    Configuración fiscal
                </h2>

                <p className="mt-0.5 text-xs text-muted-foreground">
                    Define el impuesto que
                    utilizarán tus productos
                    de forma predeterminada.
                </p>
            </div>

            {/*
            |--------------------------------------------------------------------------
            | Settings
            |--------------------------------------------------------------------------
            */}

            <div className="grid max-w-3xl gap-4 sm:grid-cols-2">

                {/*
                 * Default tax
                 */}

                <div className="space-y-1.5">
                    <Label
                        htmlFor="default-tax"
                        className="text-xs"
                    >
                        Impuesto predeterminado
                    </Label>

                    <Select
                        value={
                            config
                                .default_tax
                                .id
                        }
                        disabled={
                            saving
                        }
                        onValueChange={
                            onDefaultTaxChange
                        }
                    >
                        <SelectTrigger
                            id="default-tax"
                            className="h-9 w-full"
                        >
                            <SelectValue />
                        </SelectTrigger>

                        <SelectContent>
                            {activeTaxes.map(
                                (tax) => (
                                    <SelectItem
                                        key={
                                            tax.id
                                        }
                                        value={
                                            tax.id
                                        }
                                    >
                                        {
                                            tax.name
                                        }
                                    </SelectItem>
                                ),
                            )}
                        </SelectContent>
                    </Select>
                </div>

                {/*
                 * Prices include tax
                 */}

                <div className="space-y-1.5">
                    <Label className="text-xs">
                        Precios con impuestos incluidos
                    </Label>

                    <div className="flex h-9 items-center rounded-md border bg-muted/20 px-3 text-sm font-medium">
                        {config
                            .prices_include_tax
                            ? 'Sí'
                            : 'No'}
                    </div>
                </div>
            </div>

            {/*
            |--------------------------------------------------------------------------
            | Example
            |--------------------------------------------------------------------------
            */}

            <div className="flex flex-col gap-1 rounded-md bg-muted/30 px-3 py-2.5 sm:flex-row sm:items-center sm:gap-2">
                <span className="text-xs font-medium">
                    Ejemplo con{' '}
                    {formatMoney(
                        EXAMPLE_TOTAL,
                    )}
                </span>

                <span className="hidden text-xs text-muted-foreground sm:inline">
                    ·
                </span>

                <span className="text-xs text-muted-foreground">
                    Base{' '}
                    {formatMoney(
                        example.base,
                    )}
                    {' + '}
                    {
                        config
                            .default_tax
                            .name
                    }
                    {' '}
                    {formatMoney(
                        example.tax,
                    )}
                    {' = '}
                    total{' '}
                    {formatMoney(
                        EXAMPLE_TOTAL,
                    )}
                </span>
            </div>

            <p className="text-xs text-muted-foreground">
                Los precios capturados en el
                menú se consideran precios
                finales con impuestos incluidos.
            </p>
        </section>
    )
}

/*
|--------------------------------------------------------------------------
| Example calculation
|--------------------------------------------------------------------------
*/

function calculateExample(
    gross:
        number,

    tax:
        Tax,
) {
    if (
        tax.factor_type ===
        'exempt' ||
        Number(tax.rate) ===
        0
    ) {
        return {
            base:
                gross,

            tax:
                0,
        }
    }

    const rate =
        Number(
            tax.rate,
        )

    const divisor =
        1 +
        rate / 100

    const base =
        roundMoney(
            gross /
            divisor,
        )

    const taxAmount =
        roundMoney(
            gross -
            base,
        )

    return {
        base,

        tax:
            taxAmount,
    }
}

function roundMoney(
    value: number,
): number {
    return Math.round(
        (
            value +
            Number.EPSILON
        ) *
        100,
    ) / 100
}

function formatMoney(
    value: number,
): string {
    return new Intl.NumberFormat(
        'es-MX',
        {
            style:
                'currency',

            currency:
                'MXN',

            minimumFractionDigits:
                2,

            maximumFractionDigits:
                2,
        },
    ).format(
        value,
    )
}