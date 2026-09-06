import {
    Link,
} from '@tanstack/react-router'

import {
    AlertCircleIcon,
    Building2Icon,
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
    } =
        useSession()

    const tenantId =
        activeTenant?.id ??
        ''

    const queryClient =
        useQueryClient()

    const query =
        useQuery({
            queryKey:
                ticketSettingsQueryKeys.detail(
                    tenantId
                ),

            queryFn:
                getTicketSettings,

            enabled:
                Boolean(
                    tenantId
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
                        tenantId
                    ),
                    response,
                )
            },
        })

    return (
        <div className="space-y-5">
            <div>
                <h1 className="text-xl font-semibold tracking-tight">
                    Ticket
                </h1>

                <p className="mt-1 text-sm text-muted-foreground">
                    Personaliza el contenido y formato de tus tickets de venta.
                </p>
            </div>

            {query.isError && (
                <Alert variant="destructive">
                    <AlertCircleIcon className="size-4" />

                    <AlertDescription>
                        No pudimos cargar la configuración del ticket.
                    </AlertDescription>
                </Alert>
            )}

            {query.isPending && (
                <div className="grid gap-6 xl:grid-cols-[1fr_380px]">
                    <Skeleton className="h-[650px]" />
                    <Skeleton className="h-[600px]" />
                </div>
            )}

            {query.data &&
                !query.data.meta
                    .configuration_ready && (
                    <Card>
                        <CardContent className="flex min-h-72 flex-col items-center justify-center p-8 text-center">
                            <div className="flex size-11 items-center justify-center rounded-xl bg-muted">
                                <Building2Icon className="size-5 text-muted-foreground" />
                            </div>

                            <h2 className="mt-4 text-base font-semibold">
                                Configura primero tu negocio
                            </h2>

                            <p className="mt-1 max-w-md text-sm text-muted-foreground">
                                El ticket utiliza el nombre, teléfono y correo público configurados en la información de tu negocio.
                            </p>

                            {activeTenant && (
                                <Button
                                    asChild
                                    className="mt-5"
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
                        </CardContent>
                    </Card>
                )}

            {query.data &&
                query.data.meta
                    .configuration_ready && (
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