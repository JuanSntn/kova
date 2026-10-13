import { type FormEvent, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Loader2Icon } from 'lucide-react'
import { api, getCsrfToken } from '@/lib/api'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

type ResolveCodeResponse = {
    data: {
        url: string
    }
}

export function InvoiceLandingPage() {
    const [accessCode, setAccessCode] = useState('')

    const normalizedCode =
        normalizeAccessCode(accessCode)

    const mutation = useMutation({
        mutationFn: resolveAccessCode,
        onSuccess: (response) => {
            window.location.assign(
                response.data.url,
            )
        },
    })

    function submit(
        event: FormEvent<HTMLFormElement>,
    ) {
        event.preventDefault()

        if (
            normalizedCode.length !== 12 ||
            mutation.isPending
        ) {
            return
        }

        mutation.mutate(
            accessCode,
        )
    }

    return (
        <main className="min-h-screen bg-[#f7f7f8]">
            <div className="mx-auto flex min-h-screen w-full max-w-6xl flex-col px-4 sm:px-6 lg:px-8">
                <header className="flex h-16 items-center border-b">
                    <div className="flex items-baseline gap-2">
                        <span className="text-base font-semibold tracking-tight">
                            Kova
                        </span>

                        <span className="text-xs text-muted-foreground">
                            Facturación
                        </span>
                    </div>
                </header>

                <div className="flex flex-1 items-center py-10 lg:py-16">
                    <Card className="grid w-full overflow-hidden border bg-background p-0 shadow-sm lg:grid-cols-[0.9fr_1.1fr]">
                        <section className="hidden border-r bg-muted/25 p-10 lg:flex lg:flex-col lg:justify-between xl:p-12">
                            <div className="max-w-sm">
                                <p className="text-xs font-medium uppercase tracking-[0.18em] text-muted-foreground">
                                    Portal de facturación
                                </p>

                                <h1 className="mt-5 text-3xl font-semibold tracking-tight">
                                    Genera tu factura
                                    de forma sencilla.
                                </h1>

                                <p className="mt-4 text-sm leading-6 text-muted-foreground">
                                    Usa el código impreso en tu ticket para localizar tu compra y continuar con la captura de tus datos fiscales.
                                </p>
                            </div>

                            <div className="mt-12 space-y-5 border-t pt-6">
                                <Step
                                    number="01"
                                    title="Ubica tu código"
                                    description="Se encuentra debajo del código QR de tu ticket."
                                />

                                <Step
                                    number="02"
                                    title="Consulta tu compra"
                                    description="Validaremos que el ticket siga disponible para facturación."
                                />

                                <Step
                                    number="03"
                                    title="Captura tus datos fiscales"
                                    description="Podrás generar y descargar tu CFDI desde el mismo portal."
                                />
                            </div>
                        </section>

                        <section className="flex items-center justify-center p-6 sm:p-10 lg:p-12 xl:p-16">
                            <div className="w-full max-w-md">
                                <div className="mb-8">
                                    <p className="text-sm font-medium text-muted-foreground lg:hidden">
                                        Portal de facturación
                                    </p>

                                    <h2 className="mt-1 text-2xl font-semibold tracking-tight">
                                        Ingresa tu ticket
                                    </h2>

                                    <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                        Escribe el código de 12 caracteres que aparece en tu comprobante de compra.
                                    </p>
                                </div>

                                <form
                                    className="space-y-5"
                                    onSubmit={submit}
                                >
                                    {mutation.isError && (
                                        <Alert variant="destructive">
                                            <AlertDescription>
                                                No encontramos un ticket disponible con ese código. Verifica que esté escrito correctamente e intenta de nuevo.
                                            </AlertDescription>
                                        </Alert>
                                    )}

                                    <div className="space-y-2">
                                        <div className="flex items-center justify-between gap-3">
                                            <Label htmlFor="access_code">
                                                Código de facturación
                                            </Label>

                                            <span className="text-xs tabular-nums text-muted-foreground">
                                                {normalizedCode.length}/12
                                            </span>
                                        </div>

                                        <Input
                                            id="access_code"
                                            name="access_code"
                                            value={accessCode}
                                            maxLength={14}
                                            autoFocus
                                            autoComplete="off"
                                            autoCapitalize="characters"
                                            spellCheck={false}
                                            placeholder="K7PT-4M9X-2QW8"
                                            className="h-12 font-mono text-base font-medium uppercase tracking-[0.12em]"
                                            onChange={(event) => {
                                                setAccessCode(
                                                    formatAccessCode(
                                                        event.target.value,
                                                    ),
                                                )
                                            }}
                                        />

                                        <p className="text-xs leading-5 text-muted-foreground">
                                            Ejemplo: K7PT-4M9X-2QW8
                                        </p>
                                    </div>

                                    <Button
                                        type="submit"
                                        className="h-11 w-full"
                                        disabled={
                                            mutation.isPending ||
                                            normalizedCode.length !== 12
                                        }
                                    >
                                        {mutation.isPending ? (
                                            <>
                                                <Loader2Icon className="animate-spin" />
                                                Consultando ticket...
                                            </>
                                        ) : (
                                            'Continuar'
                                        )}
                                    </Button>
                                </form>

                                <div className="mt-8 border-t pt-5">
                                    <p className="text-xs leading-5 text-muted-foreground">
                                        Si escaneaste el código QR de tu ticket, no necesitas ingresar este código manualmente.
                                    </p>
                                </div>
                            </div>
                        </section>
                    </Card>
                </div>

                <footer className="flex min-h-16 items-center justify-between gap-4 border-t py-4 text-xs text-muted-foreground">
                    <span>
                        Kova
                    </span>

                    <span className="text-right">
                        Portal seguro de facturación
                    </span>
                </footer>
            </div>
        </main>
    )
}

function Step({
    number,
    title,
    description,
}: {
    number: string
    title: string
    description: string
}) {
    return (
        <div className="grid grid-cols-[2rem_1fr] gap-3">
            <span className="pt-0.5 font-mono text-xs text-muted-foreground">
                {number}
            </span>

            <div>
                <p className="text-sm font-medium">
                    {title}
                </p>

                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                    {description}
                </p>
            </div>
        </div>
    )
}

async function resolveAccessCode(
    accessCode: string,
) {
    await getCsrfToken()

    return (
        await api.post<ResolveCodeResponse>(
            '/api/public/factura/resolve-code',
            {
                access_code:
                    accessCode,
            },
        )
    ).data
}

function normalizeAccessCode(
    value: string,
): string {
    return value
        .toUpperCase()
        .replace(
            /[^2-9A-HJ-KM-NP-Z]/g,
            '',
        )
        .slice(
            0,
            12,
        )
}

function formatAccessCode(
    value: string,
): string {
    const normalized =
        normalizeAccessCode(
            value,
        )

    return normalized
        .match(
            /.{1,4}/g,
        )
        ?.join(
            '-',
        )
        ?? ''
}