import {
    AlertCircleIcon,
} from 'lucide-react'
import {
    useMutation,
    useQueryClient,
} from '@tanstack/react-query'
import {
    toast,
} from 'sonner'

import {
    Alert,
    AlertDescription,
} from '@/components/ui/alert'
import {
    Button,
} from '@/components/ui/button'
import {
    Skeleton,
} from '@/components/ui/skeleton'

import {
    useSession,
} from '@/features/auth/session-context'

import {
    queryKeys,
} from '@/lib/query-keys'

import {
    getTaxApiErrorMessage,
    updateTax,
    updateTaxConfig,
} from './api'

import {
    TaxList,
} from './components/tax-list'
import {
    TaxSettingsCard,
} from './components/tax-settings-card'

import {
    useTaxes,
} from './hooks/use-taxes'

import type {
    Tax,
    TaxUpdatePayload,
} from './types'

export function TaxesPage() {
    /*
    |--------------------------------------------------------------------------
    | Tenant
    |--------------------------------------------------------------------------
    */

    const {
        activeTenant,
    } = useSession()

    const tenantId =
        activeTenant?.id ?? ''

    const queryClient =
        useQueryClient()

    /*
    |--------------------------------------------------------------------------
    | Data
    |--------------------------------------------------------------------------
    */

    const {
        taxes,
        config,
        isLoading,
        hasError,
        refetchAll,
    } = useTaxes(
        tenantId,
    )

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    */

    const invalidate =
        () =>
            queryClient
                .invalidateQueries({
                    queryKey:
                        queryKeys.taxes(
                            tenantId,
                        ),
                })

    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */

    const updateMutation =
        useMutation({
            mutationFn:
                ({
                    tax,
                    payload,
                }: {
                    tax: Tax
                    payload:
                    TaxUpdatePayload
                }) =>
                    updateTax(
                        tax.id,
                        payload,
                    ),

            onSuccess:
                () => {
                    toast.success(
                        'Impuesto actualizado.',
                    )

                    void invalidate()
                },

            onError:
                (error) => {
                    toast.error(
                        getTaxApiErrorMessage(
                            error,
                        ),
                    )
                },
        })

    /*
    |--------------------------------------------------------------------------
    | Default tax
    |--------------------------------------------------------------------------
    */

    const configMutation =
        useMutation({
            mutationFn:
                updateTaxConfig,

            onSuccess:
                () => {
                    toast.success(
                        'Impuesto predeterminado actualizado.',
                    )

                    void invalidate()
                },

            onError:
                (error) => {
                    toast.error(
                        getTaxApiErrorMessage(
                            error,
                        ),
                    )
                },
        })

    /*
    |--------------------------------------------------------------------------
    | Loading
    |--------------------------------------------------------------------------
    */

    if (isLoading) {
        return (
            <TaxesPageSkeleton />
        )
    }

    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    return (
        <div className="w-full space-y-5">

            {/*
            |--------------------------------------------------------------------------
            | Header
            |--------------------------------------------------------------------------
            */}

            <div>
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">
                        Impuestos
                    </h1>

                    <p className="mt-1 text-sm text-muted-foreground">
                        Elige entre los tratamientos fiscales
                        admitidos para productos y ventas.
                    </p>
                </div>
            </div>

            {/*
            |--------------------------------------------------------------------------
            | Error
            |--------------------------------------------------------------------------
            */}

            {hasError ||
                !config ? (
                <Alert variant="destructive">
                    <AlertCircleIcon className="size-4" />

                    <AlertDescription className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <span>
                            No pudimos cargar
                            la configuración de
                            impuestos.
                        </span>

                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            className="h-8 shrink-0"
                            onClick={() =>
                                void refetchAll()
                            }
                        >
                            Reintentar
                        </Button>
                    </AlertDescription>
                </Alert>
            ) : (
                <>
                    {/*
                    |--------------------------------------------------------------------------
                    | Tax settings
                    |--------------------------------------------------------------------------
                    */}

                    <TaxSettingsCard
                        taxes={
                            taxes
                        }
                        config={
                            config
                        }
                        saving={
                            configMutation
                                .isPending
                        }
                        onDefaultTaxChange={(
                            taxId,
                        ) =>
                            configMutation
                                .mutate(
                                    taxId,
                                )
                        }
                    />

                    {/*
                    |--------------------------------------------------------------------------
                    | Tax list
                    |--------------------------------------------------------------------------
                    */}

                    <TaxList
                        taxes={
                            taxes
                        }
                        defaultTaxId={
                            config
                                .default_tax
                                .id
                        }
                        savingId={
                            updateMutation
                                .variables
                                ?.tax
                                .id ??
                            null
                        }
                        onToggleActive={(
                            tax,
                        ) =>
                            updateMutation
                                .mutate({
                                    tax,

                                    payload: {
                                        is_active:
                                            !tax
                                                .is_active,
                                    },
                                })
                        }
                    />
                </>
            )}
        </div>
    )
}

/*
|--------------------------------------------------------------------------
| Skeleton
|--------------------------------------------------------------------------
*/

function TaxesPageSkeleton() {
    return (
        <div className="w-full space-y-5">
            <div>
                <div className="space-y-2">
                    <Skeleton className="h-6 w-32" />

                    <Skeleton className="h-4 w-80 max-w-full" />
                </div>
            </div>

            <div className="space-y-4 border-b pb-5">
                <Skeleton className="h-5 w-40" />

                <div className="grid gap-4 sm:grid-cols-2">
                    <Skeleton className="h-16 w-full" />

                    <Skeleton className="h-16 w-full" />
                </div>

                <Skeleton className="h-10 w-full" />
            </div>

            <div className="space-y-3">
                <Skeleton className="h-5 w-48" />

                <Skeleton className="h-44 w-full" />
            </div>
        </div>
    )
}
