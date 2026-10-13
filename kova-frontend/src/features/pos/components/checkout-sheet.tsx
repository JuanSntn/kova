import {
    BanknoteIcon,
    CreditCardIcon,
    LandmarkIcon,
    Loader2Icon,
} from 'lucide-react'
import {
    useEffect,
    useState,
} from 'react'

import {
    Button,
} from '@/components/ui/button'
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet'

import {
    CashPayment,
} from './cash-payment'
import { cents } from '../money'
import {
    CheckoutSuccess,
} from './checkout-success'

import type {
    CheckoutReceipt,
    PosCartItem,
    PosCartTotals,
    PosPaymentMethod,
} from '../types'

type CheckoutSheetProps = {
    taxBase?: string | number
    taxTotal?: string | number
    open: boolean

    onOpenChange: (
        open: boolean
    ) => void

    items: PosCartItem[]

    totals: PosCartTotals

    paymentMethod:
    PosPaymentMethod

    checkingOut:
    boolean

    receipt:
    CheckoutReceipt | null

    onConfirm: (
        data: {
            amountReceived?: number
        }
    ) => void

    onNewSale:
    () => void
}

export function CheckoutSheet({
    taxBase,
    taxTotal,
    open,
    onOpenChange,
    items,
    totals,
    paymentMethod,
    checkingOut,
    receipt,
    onConfirm,
    onNewSale,
}: CheckoutSheetProps) {
    /*
    |--------------------------------------------------------------------------
    | Cash state
    |--------------------------------------------------------------------------
    */

    const [
        amountReceived,
        setAmountReceived,
    ] = useState('')

    /*
    |--------------------------------------------------------------------------
    | Reset
    |--------------------------------------------------------------------------
    |
    | Cuando cerramos el checkout limpiamos
    | solamente el efectivo capturado.
    |
    */

    useEffect(() => {
        if (!open) {
            setAmountReceived('')
        }
    }, [
        open,
    ])

    /*
    |--------------------------------------------------------------------------
    | Cash values
    |--------------------------------------------------------------------------
    */

    const received =
        Number(
            amountReceived,
        )

    const validReceived =
        Number.isFinite(
            received,
        )
            ? received
            : 0

    /*
     * Este valor solamente se usa para UX.
     *
     * Laravel volverá a validar amount_received
     * contra el total oficial de la orden.
     */
    const cashIsValid =
        paymentMethod !==
        'cash' ||
        (/^\d+(\.\d{1,2})?$/.test(amountReceived) && cents(amountReceived) >= cents(totals.total))

    /*
    |--------------------------------------------------------------------------
    | Success
    |--------------------------------------------------------------------------
    |
    | Una vez que Laravel respondió correctamente,
    | el mismo Sheet cambia al ticket.
    |
    */

    if (
        receipt &&
        open
    ) {
        return (
            <Sheet
                open={
                    open
                }
                onOpenChange={
                    onOpenChange
                }
            >
                <SheetContent
                    side="right"
                    className="
                        w-full
                        p-0
                        sm:max-w-md
                    "
                >
                    <CheckoutSuccess
                        receipt={
                            receipt
                        }
                        onNewSale={
                            onNewSale
                        }
                    />
                </SheetContent>
            </Sheet>
        )
    }

    /*
    |--------------------------------------------------------------------------
    | Checkout
    |--------------------------------------------------------------------------
    */

    return (
        <Sheet
            open={
                open
            }
            onOpenChange={(
                nextOpen,
            ) => {
                /*
                 * No permitimos cerrar
                 * mientras Laravel procesa.
                 */
                if (
                    checkingOut
                ) {
                    return
                }

                onOpenChange(
                    nextOpen,
                )
            }}
        >
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
                {/*
                |--------------------------------------------------------------------------
                | Header
                |--------------------------------------------------------------------------
                */}

                <SheetHeader className="shrink-0 border-b px-6 py-5 text-left">
                    <SheetTitle>
                        Cobrar venta
                    </SheetTitle>

                    <SheetDescription>
                        Revisa el total y confirma
                        que el pago fue recibido.
                    </SheetDescription>
                </SheetHeader>

                {/*
                |--------------------------------------------------------------------------
                | Content
                |--------------------------------------------------------------------------
                */}

                <div className="min-h-0 flex-1 overflow-y-auto px-6 py-5">

                    {/*
                    |--------------------------------------------------------------------------
                    | Total
                    |--------------------------------------------------------------------------
                    */}

                    <div className="text-center">
                        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                            Total a cobrar
                        </p>

                        <p className="mt-1 text-4xl font-bold tracking-tight">
                            {formatMoney(
                                totals.total,
                            )}
                        </p>
                    </div>

                    {/*
                    |--------------------------------------------------------------------------
                    | Payment method
                    |--------------------------------------------------------------------------
                    */}

                    <div className="mt-6">
                        <p className="text-xs font-medium text-muted-foreground">
                            Método de pago
                        </p>

                        <div className="mt-2 flex items-center gap-3 rounded-lg border px-4 py-3">
                            <div className="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted">
                                <PaymentIcon
                                    method={
                                        paymentMethod
                                    }
                                />
                            </div>

                            <div className="min-w-0">
                                <p className="text-sm font-medium">
                                    {getPaymentMethodLabel(
                                        paymentMethod,
                                    )}
                                </p>

                                <p className="text-xs text-muted-foreground">
                                    {getPaymentMethodDescription(
                                        paymentMethod,
                                    )}
                                </p>
                            </div>
                        </div>
                    </div>

                    {/*
                    |--------------------------------------------------------------------------
                    | Cash
                    |--------------------------------------------------------------------------
                    */}

                    {paymentMethod ===
                        'cash' && (
                            <div className="mt-6">
                                <CashPayment
                                    total={
                                        totals.total
                                    }
                                    amountReceived={
                                        amountReceived
                                    }
                                    onAmountReceivedChange={
                                        setAmountReceived
                                    }
                                />
                            </div>
                        )}

                    {/*
                    |--------------------------------------------------------------------------
                    | Card
                    |--------------------------------------------------------------------------
                    */}

                    {paymentMethod ===
                        'card' && (
                            <div className="mt-6 rounded-lg border bg-muted/30 px-4 py-3">
                                <div className="flex gap-3">
                                    <CreditCardIcon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />

                                    <div>
                                        <p className="text-sm font-medium">
                                            Verifica el pago
                                        </p>

                                        <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                                            Confirma que la
                                            terminal bancaria
                                            haya aprobado el pago
                                            antes de registrar la
                                            venta.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        )}

                    {/*
                    |--------------------------------------------------------------------------
                    | Transfer
                    |--------------------------------------------------------------------------
                    */}

                    {paymentMethod ===
                        'transfer' && (
                            <div className="mt-6 rounded-lg border bg-muted/30 px-4 py-3">
                                <div className="flex gap-3">
                                    <LandmarkIcon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />

                                    <div>
                                        <p className="text-sm font-medium">
                                            Verifica la transferencia
                                        </p>

                                        <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                                            Confirma que el pago
                                            haya sido recibido
                                            antes de registrar la
                                            venta.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        )}

                    {/*
                    |--------------------------------------------------------------------------
                    | Order summary
                    |--------------------------------------------------------------------------
                    */}

                    <div className="mt-6">
                        <div className="mb-2 flex items-center justify-between gap-4">
                            <p className="text-sm font-medium">
                                Resumen
                            </p>

                            <span className="text-xs text-muted-foreground">
                                {getItemCount(
                                    items,
                                )}{' '}
                                {getItemCount(
                                    items,
                                ) ===
                                    1
                                    ? 'artículo'
                                    : 'artículos'}
                            </span>
                        </div>

                        <div className="overflow-hidden rounded-lg border">
                            {items.map(
                                (item) => (
                                    <div
                                        key={
                                            item.line_id
                                        }
                                        className="
                                            flex
                                            items-start
                                            justify-between
                                            gap-3
                                            border-b
                                            px-3
                                            py-2.5
                                            last:border-b-0
                                        "
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-medium">
                                                {
                                                    item.name_snapshot
                                                }
                                            </p>

                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                {
                                                    item.quantity
                                                }{' '}
                                                ×{' '}
                                                {formatMoney(
                                                    item.unit_price,
                                                )}
                                            </p>
                                        </div>

                                        <span className="shrink-0 text-sm font-medium tabular-nums">
                                            {formatMoney(
                                                item.unit_price *
                                                item.quantity,
                                            )}
                                        </span>
                                    </div>
                                ),
                            )}
                        </div>
                    </div>
                </div>

                {/*
                |--------------------------------------------------------------------------
                | Footer
                |--------------------------------------------------------------------------
                */}

                <SheetFooter className="shrink-0 border-t bg-background px-6 py-4">
                    <div className="w-full space-y-3">
                        {taxTotal !== undefined && <div className="text-sm text-muted-foreground">
                            <div className="flex justify-between"><span>Base</span><span>{formatMoney(Number(taxBase ?? 0))}</span></div>
                            <div className="flex justify-between"><span>Impuestos incluidos</span><span>{formatMoney(Number(taxTotal))}</span></div>
                        </div>}

                        {/*
                         * Total
                         */}

                        <div className="flex items-center justify-between gap-4">
                            <span className="text-sm font-medium">
                                Total
                            </span>

                            <span className="text-xl font-bold tabular-nums">
                                {formatMoney(
                                    totals.total,
                                )}
                            </span>
                        </div>

                        {/*
                         * Invalid cash helper
                         */}

                        {paymentMethod ===
                            'cash' &&
                            amountReceived !==
                            '' &&
                            !cashIsValid && (
                                <p className="text-xs text-destructive">
                                    El efectivo recibido
                                    debe ser igual o mayor
                                    al total de la venta.
                                </p>
                            )}

                        {/*
                         * Actions
                         */}

                        <div className="flex gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                className="flex-1"
                                disabled={
                                    checkingOut
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
                                type="button"
                                className="flex-1"
                                disabled={
                                    checkingOut ||
                                    !cashIsValid
                                }
                                onClick={() => {
                                    /*
                                     * Importante:
                                     *
                                     * NO enviamos change.
                                     *
                                     * Laravel recibe
                                     * amount_received y
                                     * calcula change_amount.
                                     */
                                    onConfirm({
                                        amountReceived:
                                            paymentMethod ===
                                                'cash'
                                                ? validReceived
                                                : undefined,
                                    })
                                }}
                            >
                                {checkingOut ? (
                                    <>
                                        <Loader2Icon className="size-4 animate-spin" />

                                        Procesando...
                                    </>
                                ) : (
                                    <>
                                        Confirmar{' '}

                                        {formatMoney(
                                            totals.total,
                                        )}
                                    </>
                                )}
                            </Button>
                        </div>
                    </div>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    )
}

/*
|--------------------------------------------------------------------------
| Payment icon
|--------------------------------------------------------------------------
*/

function PaymentIcon({
    method,
}: {
    method:
    PosPaymentMethod
}) {
    switch (method) {
        case 'cash':
            return (
                <BanknoteIcon className="size-5" />
            )

        case 'card':
            return (
                <CreditCardIcon className="size-5" />
            )

        case 'transfer':
            return (
                <LandmarkIcon className="size-5" />
            )
    }
}

/*
|--------------------------------------------------------------------------
| Payment label
|--------------------------------------------------------------------------
*/

function getPaymentMethodLabel(
    method:
        PosPaymentMethod,
): string {
    switch (method) {
        case 'cash':
            return 'Efectivo'

        case 'card':
            return 'Tarjeta'

        case 'transfer':
            return 'Transferencia'
    }
}

/*
|--------------------------------------------------------------------------
| Payment description
|--------------------------------------------------------------------------
*/

function getPaymentMethodDescription(
    method:
        PosPaymentMethod,
): string {
    switch (method) {
        case 'cash':
            return 'Pago recibido en caja'

        case 'card':
            return 'Terminal bancaria externa'

        case 'transfer':
            return 'Transferencia bancaria'
    }
}

/*
|--------------------------------------------------------------------------
| Item count
|--------------------------------------------------------------------------
*/

function getItemCount(
    items:
        PosCartItem[],
): number {
    return items.reduce(
        (
            total,
            item,
        ) =>
            total +
            item.quantity,
        0,
    )
}

/*
|--------------------------------------------------------------------------
| Money
|--------------------------------------------------------------------------
*/

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
