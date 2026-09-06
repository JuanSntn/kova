import {
    BanknoteIcon,
    CreditCardIcon,
    LandmarkIcon,
} from 'lucide-react'

import { Button } from '@/components/ui/button'

import type {
    PosPaymentMethod,
} from '../types'

type PosPaymentMethodProps = {
    value: PosPaymentMethod

    onChange: (
        value: PosPaymentMethod
    ) => void
}

const paymentMethods: Array<{
    value: PosPaymentMethod
    label: string
    icon: typeof BanknoteIcon
}> = [
        {
            value: 'cash',
            label: 'Efectivo',
            icon: BanknoteIcon,
        },
        {
            value: 'card',
            label: 'Tarjeta',
            icon: CreditCardIcon,
        },
        {
            value: 'transfer',
            label: 'Transferencia',
            icon: LandmarkIcon,
        },
    ]

export function PosPaymentMethod({
    value,
    onChange,
}: PosPaymentMethodProps) {
    return (
        <div className="space-y-2">
            <p className="text-xs font-medium">
                Método de pago
            </p>

            <div className="grid grid-cols-3 gap-2">
                {paymentMethods.map(
                    (method) => {
                        const Icon =
                            method.icon

                        const selected =
                            value ===
                            method.value

                        return (
                            <Button
                                key={
                                    method.value
                                }
                                type="button"
                                variant={
                                    selected
                                        ? 'default'
                                        : 'outline'
                                }
                                className="h-auto min-h-14 flex-col gap-1 px-2 py-2"
                                onClick={() =>
                                    onChange(
                                        method.value,
                                    )
                                }
                            >
                                <Icon className="size-4" />

                                <span className="text-[11px]">
                                    {
                                        method.label
                                    }
                                </span>
                            </Button>
                        )
                    },
                )}
            </div>
        </div>
    )
}