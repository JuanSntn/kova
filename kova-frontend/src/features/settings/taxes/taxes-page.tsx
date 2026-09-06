import {
    AlertCircleIcon,
} from 'lucide-react'
import {
    useMutation,
    useQueryClient,
} from '@tanstack/react-query'
import {
    useState,
} from 'react'
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
    createTax,
    getTaxApiErrorMessage,
    updateTax,
    updateTaxConfig,
} from './api'

import {
    TaxForm,
} from './components/tax-form'
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
    TaxPayload,
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
    | Form state
    |--------------------------------------------------------------------------
    */

    const [
        taxFormOpen,
        setTaxFormOpen,
    ] = useState(false)

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
    | Create
    |--------------------------------------------------------------------------
    */

    const createMutation =
        useMutation({
            mutationFn:
                createTax,

            onSuccess:
                () => {
                    toast.success(
                        'Impuesto agregado.',
                    )

                    setTaxFormOpen(
                        false,
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
                    Partial<TaxPayload>
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

            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">
                        Impuestos
                    </h1>

                    <p className="mt-1 text-sm text-muted-foreground">
                        Configura los impuestos usados
                        por productos y ventas.
                    </p>
                </div>

                <TaxForm
                    open={
                        taxFormOpen
                    }
                    onOpenChange={
                        setTaxFormOpen
                    }
                    saving={
                        createMutation
                            .isPending
                    }
                    onSubmit={(
                        payload,
                    ) =>
                        createMutation
                            .mutate(
                                payload,
                            )
                    }
                />
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
            <div className="flex items-start justify-between gap-4">
                <div className="space-y-2">
                    <Skeleton className="h-6 w-32" />

                    <Skeleton className="h-4 w-80 max-w-full" />
                </div>

                <Skeleton className="h-9 w-36" />
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