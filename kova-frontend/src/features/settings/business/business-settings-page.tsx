import {
    AlertCircleIcon,
    CheckCircle2Icon,
} from 'lucide-react'

import {
    ticketSettingsQueryKeys,
} from '@/features/settings/tickets/query-keys'

import {
    useMutation,
    useQuery,
    useQueryClient,
} from '@tanstack/react-query'

import {
    Alert,
    AlertDescription,
} from '@/components/ui/alert'

import {
    Skeleton,
} from '@/components/ui/skeleton'

import {
    useSession,
} from '@/features/auth/session-context'

import {
    getBusinessProfile,
    updateBusinessProfile,
} from './api'

import {
    BusinessProfileForm,
} from './components/business-profile-form'

import {
    businessSettingsQueryKeys,
} from './query-keys'

export function BusinessSettingsPage() {
    const {
        activeTenant,
    } = useSession()

    const tenantId =
        activeTenant?.id ?? ''

    const queryClient =
        useQueryClient()

    const query =
        useQuery({
            queryKey:
                businessSettingsQueryKeys.detail(
                    tenantId,
                ),

            queryFn:
                getBusinessProfile,

            enabled:
                Boolean(
                    tenantId,
                ),
        })

    const mutation =
        useMutation({
            mutationFn:
                updateBusinessProfile,

            onSuccess: async (
                profile,
            ) => {
                queryClient.setQueryData(
                    businessSettingsQueryKeys.detail(
                        tenantId,
                    ),
                    profile,
                )

                await queryClient.invalidateQueries({
                    queryKey:
                        ticketSettingsQueryKeys.detail(
                            tenantId,
                        ),
                })
            },
        })

    return (
        <div className="space-y-6">
            {/*
            |--------------------------------------------------------------------------
            | Header
            |--------------------------------------------------------------------------
            */}

            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">
                        Información del negocio
                    </h1>

                    <p className="mt-1 max-w-2xl text-sm leading-6 text-muted-foreground">
                        Administra los datos públicos de tu negocio.
                        Esta información puede aparecer en tickets y
                        otras áreas visibles para tus clientes.
                    </p>
                </div>

                {query.data?.is_complete && (
                    <div className="inline-flex shrink-0 items-center gap-1.5 text-xs font-medium text-muted-foreground">
                        <CheckCircle2Icon className="size-4 text-emerald-600" />

                        Perfil completo
                    </div>
                )}
            </div>

            {/*
            |--------------------------------------------------------------------------
            | Query error
            |--------------------------------------------------------------------------
            */}

            {query.isError && (
                <Alert variant="destructive">
                    <AlertCircleIcon className="size-4" />

                    <AlertDescription>
                        No pudimos cargar la información del negocio.
                    </AlertDescription>
                </Alert>
            )}

            {/*
            |--------------------------------------------------------------------------
            | Loading
            |--------------------------------------------------------------------------
            */}

            {query.isPending && (
                <BusinessSettingsSkeleton />
            )}

            {/*
            |--------------------------------------------------------------------------
            | Incomplete profile notice
            |--------------------------------------------------------------------------
            */}

            {query.data &&
                !query.data.is_complete && (
                    <Alert>
                        <AlertCircleIcon className="size-4" />

                        <AlertDescription>
                            Completa el nombre, teléfono y correo público para habilitar la configuración de tus tickets.
                        </AlertDescription>
                    </Alert>
                )}

            {/*
            |--------------------------------------------------------------------------
            | Form
            |--------------------------------------------------------------------------
            */}

            {query.data && (
                <BusinessProfileForm
                    profile={
                        query.data
                    }
                    isSaving={
                        mutation.isPending
                    }
                    onSave={
                        mutation.mutateAsync
                    }
                />
            )}
        </div>
    )
}

function BusinessSettingsSkeleton() {
    return (
        <div className="space-y-5">
            <Skeleton className="h-[330px] w-full rounded-xl" />

            <Skeleton className="h-[220px] w-full rounded-xl" />
        </div>
    )
}