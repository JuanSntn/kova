import {
  Link,
  useRouterState,
} from '@tanstack/react-router'

import {
  CheckCircle2,
  LoaderCircle,
  Send,
} from 'lucide-react'

import {
  type FormEvent,
  useState,
} from 'react'

import {
  Alert,
  AlertDescription,
} from '@/components/ui/alert'

import {
  Button,
} from '@/components/ui/button'

import {
  Field,
  FieldDescription,
  FieldGroup,
  FieldLabel,
} from '@/components/ui/field'

import {
  Input,
} from '@/components/ui/input'

import {
  getApiError,
  resendVerification,
} from '@/features/auth/api'

import {
  AuthLayout,
} from '@/features/auth/components/auth-layout'

export function VerifyEmailPage() {
  const routeState =
    useRouterState({
      select: (state) =>
        state.location.state,
    })

  const [
    email,
    setEmail,
  ] = useState(
    routeState.verificationEmail ??
    '',
  )

  const [
    isSending,
    setIsSending,
  ] = useState(false)

  const [
    message,
    setMessage,
  ] = useState('')

  const [
    error,
    setError,
  ] = useState('')

  async function handleResend(
    event: FormEvent<HTMLFormElement>,
  ) {
    event.preventDefault()

    setMessage('')
    setError('')
    setIsSending(true)

    try {
      const response =
        await resendVerification(
          email
            .trim()
            .toLowerCase(),
        )

      setMessage(
        response.message,
      )
    } catch (exception) {
      setError(
        getApiError(
          exception,
        ).message,
      )
    } finally {
      setIsSending(false)
    }
  }

  return (
    <AuthLayout>
      <main className="flex min-h-svh w-full items-center justify-center bg-background px-6 py-10">
        <section className="w-full max-w-md">
          {/*
          |--------------------------------------------------------------------------
          | Header
          |--------------------------------------------------------------------------
          */}

          <div className="mb-7 text-center">
            <h1 className="text-3xl font-semibold tracking-tight">
              Revisa tu correo
            </h1>

            <p className="mt-2 text-sm leading-6 text-muted-foreground">
              {routeState.justRegistered
                ? 'Tu negocio ya está creado. Solo falta confirmar que este correo es tuyo.'
                : 'Debes confirmar tu correo antes de poder iniciar sesión.'}
            </p>
          </div>

          {/*
          |--------------------------------------------------------------------------
          | Form
          |--------------------------------------------------------------------------
          */}

          <form
            onSubmit={
              handleResend
            }
            noValidate
          >
            <FieldGroup className="gap-4">
              {message && (
                <Alert>
                  <CheckCircle2 className="size-4" />

                  <AlertDescription className="text-sm">
                    {message}
                  </AlertDescription>
                </Alert>
              )}

              {error && (
                <Alert variant="destructive">
                  <AlertDescription className="text-sm">
                    {error}
                  </AlertDescription>
                </Alert>
              )}

              <Field className="gap-1.5">
                <FieldLabel
                  htmlFor="verification-email"
                  className="text-sm"
                >
                  Correo electrónico
                </FieldLabel>

                <Input
                  id="verification-email"
                  type="email"
                  autoComplete="email"
                  inputMode="email"
                  value={email}
                  onChange={(
                    event,
                  ) =>
                    setEmail(
                      event.target
                        .value,
                    )
                  }
                  className="h-10 text-sm"
                  disabled={
                    isSending
                  }
                  required
                />
              </Field>

              <Button
                type="submit"
                className="h-10 w-full text-sm"
                disabled={
                  isSending ||
                  !email.trim()
                }
              >
                {isSending ? (
                  <>
                    <LoaderCircle
                      className="size-4 animate-spin"
                      aria-hidden
                    />

                    Enviando...
                  </>
                ) : (
                  <>
                    <Send
                      className="size-4"
                      aria-hidden
                    />

                    Reenviar enlace
                  </>
                )}
              </Button>

              <FieldDescription className="text-center text-sm">
                ¿Ya verificaste tu correo?{' '}

                <Link
                  to="/login"
                  className="font-medium text-foreground underline-offset-4 hover:underline"
                >
                  Inicia sesión
                </Link>
              </FieldDescription>
            </FieldGroup>
          </form>

          {/*
          |--------------------------------------------------------------------------
          | Help
          |--------------------------------------------------------------------------
          */}

          <div className="mt-7 border-t pt-5">
            <p className="text-center text-sm leading-6 text-muted-foreground">
              Si no encuentras el correo, revisa tu carpeta de spam o correo no deseado.
            </p>
          </div>
        </section>
      </main>
    </AuthLayout>
  )
}