import axios from 'axios'
import { useEmailVerification } from '@/features/auth/use-email-verification'
import {
  Link,
  useNavigate,
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
  useEffect,
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
  const navigate = useNavigate()
  const verification = useEmailVerification()
  useEffect(() => {
    if (verification.status?.status === 'verified') {
      void navigate({ to: '/email-verified', replace: true })
    }
  }, [verification.status?.status, navigate])

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

    if (isSending || verification.remaining > 0) return

    setMessage('')
    setError('')
    setIsSending(true)

    try {
      const response =
        await resendVerification(
          (verification.status?.email ?? email)
            .trim()
            .toLowerCase(),
        )

      setMessage(response.message)
      verification.cooldown(response.retry_after ?? 60)
      await verification.refresh()
    } catch (exception) {
      if (axios.isAxiosError(exception) && exception.response?.status === 429) {
        const seconds = Number(exception.response.headers['retry-after'] ?? 60)
        verification.cooldown(Number.isFinite(seconds) ? seconds : 60)
        setError('Espera a que termine la cuenta regresiva antes de reenviar.')
        return
      }
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
          >
            <FieldGroup className="gap-4">
              {(message || verification.status?.message) && (
                <Alert role="status" aria-live="polite">
                  <CheckCircle2 className="size-4" />

                  <AlertDescription className="text-sm">
                    {message || verification.status?.message}
                  </AlertDescription>
                </Alert>
              )}

              {(error || verification.error) && (
                <Alert variant="destructive">
                  <AlertDescription className="text-sm">
                    {error || verification.error}
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
                  value={verification.status?.email ?? email}
                  readOnly={Boolean(verification.status?.email)}
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
                  verification.remaining > 0 ||
                  !(verification.status?.email ?? email).trim()
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

                    {verification.remaining > 0 ? `Reenviar en ${verification.remaining} s` : 'Reenviar enlace'}
                  </>
                )}
              </Button>

              <p className="text-center text-sm text-muted-foreground">
                {verification.status?.status === 'pending'
                  ? 'Esta página se actualizará cuando confirmes tu correo, incluso si abres el enlace en otro dispositivo.'
                  : verification.status?.status === 'unavailable'
                    ? 'Inicia sesión con tu contraseña para recuperar el seguimiento de la verificación.'
                    : 'Comprobando el estado de tu correo…'}
              </p>
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