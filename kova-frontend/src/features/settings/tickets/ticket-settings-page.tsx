import {
    Link,
} from '@tanstack/react-router'

import {
    AlertCircleIcon,
    Building2Icon,
    CheckCircle2Icon,
} from 'lucide-react'

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
    Button,
} from '@/components/ui/button'

import {
    Card,
    CardContent,
} from '@/components/ui/card'

import {
    Skeleton,
} from '@/components/ui/skeleton'

import {
    useSession,
} from '@/features/auth/session-context'

import {
    getTicketSettings,
    updateTicketSettings,
} from './api'

import {
    TicketSettingsForm,
} from './components/ticket-settings-form'

import {
    ticketSettingsQueryKeys,
} from './query-keys'

export function TicketSettingsPage() {
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
                ticketSettingsQueryKeys.detail(
                    tenantId,
                ),

            queryFn:
                getTicketSettings,
            staleTime: 0,
            refetchOnMount: 'always',

            enabled:
                Boolean(
                    tenantId,
                ),
        })

    const mutation =
        useMutation({
            mutationFn:
                updateTicketSettings,

            onSuccess: (
                response,
            ) => {
                queryClient.setQueryData(
                    ticketSettingsQueryKeys.detail(
                        tenantId,
                    ),
                    response,
                )
            },
        })

    const configurationReady =
        query.data?.meta
            .configuration_ready ??
        false

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
                        Ticket
                    </h1>

                    <p className="mt-1 max-w-2xl text-sm leading-6 text-muted-foreground">
                        Personaliza el contenido y formato de tus tickets
                        de venta para mantener una presentación clara y
                        consistente para tus clientes.
                    </p>
                </div>

                {query.data &&
                    configurationReady && (
                        <div className="inline-flex shrink-0 items-center gap-1.5 text-xs font-medium text-muted-foreground">
                            <CheckCircle2Icon className="size-4 text-emerald-600" />

                            Configuración disponible
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
                        No pudimos cargar la configuración del ticket.
                    </AlertDescription>
                </Alert>
            )}

            {/*
            |--------------------------------------------------------------------------
            | Loading
            |--------------------------------------------------------------------------
            */}

            {query.isPending && (
                <TicketSettingsSkeleton />
            )}

            {/*
            |--------------------------------------------------------------------------
            | Business profile required
            |--------------------------------------------------------------------------
            */}

            {query.data &&
                !configurationReady && (
                    <div className="space-y-5">
                        <Alert>
                            <AlertCircleIcon className="size-4" />

                            <AlertDescription>
                                Completa la información pública de tu negocio
                                antes de personalizar tus tickets.
                            </AlertDescription>
                        </Alert>

                        <Card className="shadow-none">
                            <CardContent className="p-5 sm:p-6">
                                <div className="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="flex min-w-0 items-start gap-4">
                                        <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                                            <Building2Icon className="size-5 text-muted-foreground" />
                                        </div>

                                        <div className="min-w-0">
                                            <h2 className="text-sm font-semibold">
                                                Configura primero tu negocio
                                            </h2>

                                            <p className="mt-1 max-w-xl text-sm leading-6 text-muted-foreground">
                                                El ticket utiliza el nombre,
                                                teléfono y correo público
                                                configurados en la información
                                                de tu negocio.
                                            </p>
                                        </div>
                                    </div>

                                    {activeTenant && (
                                        <Button
                                            asChild
                                            size="sm"
                                            className="shrink-0"
                                        >
                                            <Link
                                                to="/$tenantSlug/settings/business"
                                                params={{
                                                    tenantSlug:
                                                        activeTenant.slug,
                                                }}
                                            >
                                                Configurar negocio
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                )}

            {/*
            |--------------------------------------------------------------------------
            | Ticket settings form
            |--------------------------------------------------------------------------
            */}

            {query.data &&
                configurationReady && (
                    <TicketSettingsForm
                        response={
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

function TicketSettingsSkeleton() {
    return (
        <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_380px]">
            <Skeleton className="h-[650px] w-full rounded-xl" />

            <Skeleton className="h-[600px] w-full rounded-xl" />
        </div>
    )
}
