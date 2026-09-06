import {
    Loader2Icon,
    PlusIcon,
} from 'lucide-react'
import {
    FormEvent,
    useEffect,
    useState,
} from 'react'

import {
    Button,
} from '@/components/ui/button'
import {
    Input,
} from '@/components/ui/input'
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
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet'

import type {
    TaxPayload,
} from '../types'

type TaxFactorType =
    | 'rate'
    | 'exempt'

type TaxFormProps = {
    open: boolean

    onOpenChange: (
        open: boolean
    ) => void

    saving: boolean

    onSubmit: (
        payload: TaxPayload
    ) => void
}

export function TaxForm({
    open,
    onOpenChange,
    saving,
    onSubmit,
}: TaxFormProps) {
    /*
    |--------------------------------------------------------------------------
    | State
    |--------------------------------------------------------------------------
    */

    const [
        name,
        setName,
    ] = useState('')

    const [
        code,
        setCode,
    ] = useState('iva')

    const [
        factorType,
        setFactorType,
    ] =
        useState<TaxFactorType>(
            'rate',
        )

    const [
        rate,
        setRate,
    ] = useState('16')

    /*
    |--------------------------------------------------------------------------
    | Reset
    |--------------------------------------------------------------------------
    */

    useEffect(() => {
        if (open) {
            return
        }

        setName('')
        setCode('iva')
        setFactorType(
            'rate',
        )
        setRate('16')
    }, [
        open,
    ])

    /*
    |--------------------------------------------------------------------------
    | Submit
    |--------------------------------------------------------------------------
    */

    function handleSubmit(
        event:
            FormEvent<HTMLFormElement>,
    ) {
        event.preventDefault()

        const normalizedName =
            name.trim()

        const normalizedCode =
            code
                .trim()
                .toLowerCase()

        if (
            normalizedName ===
            '' ||
            normalizedCode ===
            ''
        ) {
            return
        }

        const parsedRate =
            factorType ===
                'exempt'
                ? 0
                : Number(rate)

        if (
            factorType ===
            'rate' &&
            (
                !Number.isFinite(
                    parsedRate,
                ) ||
                parsedRate < 0
            )
        ) {
            return
        }

        onSubmit({
            name:
                normalizedName,

            code:
                normalizedCode,

            factor_type:
                factorType,

            rate:
                parsedRate,

            is_active:
                true,
        })
    }

    return (
        <Sheet
            open={
                open
            }
            onOpenChange={(
                nextOpen,
            ) => {
                if (
                    saving
                ) {
                    return
                }

                onOpenChange(
                    nextOpen,
                )
            }}
        >
            {/*
            |--------------------------------------------------------------------------
            | Trigger
            |--------------------------------------------------------------------------
            */}

            <SheetTrigger asChild>
                <Button
                    type="button"
                    size="sm"
                    className="h-9"
                >
                    <PlusIcon className="size-4" />

                    Nuevo impuesto
                </Button>
            </SheetTrigger>

            {/*
            |--------------------------------------------------------------------------
            | Sidebar
            |--------------------------------------------------------------------------
            */}

            <SheetContent
                side="right"
                className="
                    flex
                    w-full
                    flex-col
                    p-0
                    sm:max-w-md
                "
            >
                <form
                    onSubmit={
                        handleSubmit
                    }
                    className="flex h-full min-h-0 flex-col"
                >
                    {/*
                    |--------------------------------------------------------------------------
                    | Header
                    |--------------------------------------------------------------------------
                    */}

                    <SheetHeader className="shrink-0 border-b px-6 py-5 text-left">
                        <SheetTitle>
                            Nuevo impuesto
                        </SheetTitle>

                        <SheetDescription>
                            Agrega un impuesto
                            disponible para tus
                            productos y ventas.
                        </SheetDescription>
                    </SheetHeader>

                    {/*
                    |--------------------------------------------------------------------------
                    | Content
                    |--------------------------------------------------------------------------
                    */}

                    <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-5">

                        {/*
                         * Name
                         */}

                        <div className="space-y-1.5">
                            <Label
                                htmlFor="tax-name"
                            >
                                Nombre
                            </Label>

                            <Input
                                id="tax-name"
                                value={
                                    name
                                }
                                placeholder="Ej. IVA 8%"
                                autoFocus
                                disabled={
                                    saving
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setName(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            />

                            <p className="text-xs text-muted-foreground">
                                Nombre que verá el
                                usuario al seleccionar
                                el impuesto.
                            </p>
                        </div>

                        {/*
                         * Code
                         */}

                        <div className="space-y-1.5">
                            <Label
                                htmlFor="tax-code"
                            >
                                Código
                            </Label>

                            <Input
                                id="tax-code"
                                value={
                                    code
                                }
                                placeholder="iva"
                                disabled={
                                    saving
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setCode(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            />

                            <p className="text-xs text-muted-foreground">
                                Identificador interno
                                del impuesto, por
                                ejemplo{' '}
                                <span className="font-medium">
                                    iva
                                </span>
                                .
                            </p>
                        </div>

                        {/*
                         * Factor type
                         */}

                        <div className="space-y-1.5">
                            <Label>
                                Tipo
                            </Label>

                            <Select
                                value={
                                    factorType
                                }
                                disabled={
                                    saving
                                }
                                onValueChange={(
                                    value,
                                ) => {
                                    const type =
                                        value as TaxFactorType

                                    setFactorType(
                                        type,
                                    )

                                    if (
                                        type ===
                                        'exempt'
                                    ) {
                                        setRate(
                                            '0',
                                        )
                                    }
                                }}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>

                                <SelectContent>
                                    <SelectItem value="rate">
                                        Tasa
                                    </SelectItem>

                                    <SelectItem value="exempt">
                                        Exento
                                    </SelectItem>
                                </SelectContent>
                            </Select>

                            <p className="text-xs text-muted-foreground">
                                Usa Tasa cuando el
                                impuesto tenga un
                                porcentaje. Usa Exento
                                cuando no aplique
                                impuesto.
                            </p>
                        </div>

                        {/*
                         * Rate
                         */}

                        <div className="space-y-1.5">
                            <Label
                                htmlFor="tax-rate"
                            >
                                Tasa
                            </Label>

                            <div className="relative">
                                <Input
                                    id="tax-rate"
                                    type="number"
                                    min="0"
                                    step="0.0001"
                                    value={
                                        factorType ===
                                            'exempt'
                                            ? '0'
                                            : rate
                                    }
                                    disabled={
                                        saving ||
                                        factorType ===
                                        'exempt'
                                    }
                                    className="pr-9"
                                    onChange={(
                                        event,
                                    ) =>
                                        setRate(
                                            event
                                                .target
                                                .value,
                                        )
                                    }
                                />

                                <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">
                                    %
                                </span>
                            </div>

                            {factorType ===
                                'exempt' ? (
                                <p className="text-xs text-muted-foreground">
                                    Los impuestos
                                    exentos siempre
                                    utilizan una tasa
                                    de 0%.
                                </p>
                            ) : (
                                <p className="text-xs text-muted-foreground">
                                    Por ejemplo, para
                                    IVA del 16% captura
                                    16.
                                </p>
                            )}
                        </div>

                        {/*
                        |--------------------------------------------------------------------------
                        | Example
                        |--------------------------------------------------------------------------
                        */}

                        <div className="rounded-lg border bg-muted/20 p-4">
                            <p className="text-sm font-medium">
                                Cómo funciona
                            </p>

                            <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                                Los precios de Kova
                                son precios finales.
                                El impuesto se
                                desglosa internamente
                                y no se agrega encima
                                del precio mostrado al
                                cliente.
                            </p>
                        </div>
                    </div>

                    {/*
                    |--------------------------------------------------------------------------
                    | Footer
                    |--------------------------------------------------------------------------
                    */}

                    <SheetFooter className="shrink-0 border-t bg-background px-6 py-4">
                        <div className="flex w-full items-center justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                disabled={
                                    saving
                                }
                                onClick={() =>
                                    onOpenChange(
                                        false,
                                    )
                                }
                            >
                                Cancelar
                            </Button>

                            <Button
                                type="submit"
                                disabled={
                                    saving ||
                                    name
                                        .trim() ===
                                    '' ||
                                    code
                                        .trim() ===
                                    ''
                                }
                            >
                                {saving && (
                                    <Loader2Icon className="size-4 animate-spin" />
                                )}

                                Guardar impuesto
                            </Button>
                        </div>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    )
}