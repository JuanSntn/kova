import {
  useEffect,
  useMemo,
  useState,
  type FormEvent,
} from 'react'

import { Link } from '@tanstack/react-router'
import axios from 'axios'
import {
  CheckCircle2Icon,
  CircleIcon,
  EyeIcon,
  EyeOffIcon,
  LoaderCircle,
} from 'lucide-react'

import { AuthLayout } from '@/features/auth/components/auth-layout'
import {
  Alert,
  AlertDescription,
} from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Field,
  FieldLabel,
} from '@/components/ui/field'

import {
  api,
  getCsrfToken,
} from '@/lib/api'
import { getApiError } from '@/features/auth/api'

export function PasswordRecoveryPage({
  reset = false,
}: {
  reset?: boolean
}) {
  const [link] = useState(
    () =>
      new URLSearchParams(
        window.location.hash.slice(1),
      ),
  )

  const [email, setEmail] = useState(
    reset
      ? link.get('email') ?? ''
      : '',
  )

  const [password, setPassword] =
    useState('')

  const [
    confirmation,
    setConfirmation,
  ] = useState('')

  const [
    showPassword,
    setShowPassword,
  ] = useState(false)

  const [
    showConfirmation,
    setShowConfirmation,
  ] = useState(false)

  const [sending, setSending] =
    useState(false)

  const [message, setMessage] =
    useState('')

  const [error, setError] =
    useState('')

  const [done, setDone] =
    useState(false)

  const [
    remaining,
    setRemaining,
  ] = useState(0)

  const [invalid, setInvalid] =
    useState(
      reset &&
      (
        !link.get('token') ||
        !link.get('email')
      ),
    )

  const [local, setLocal] =
    useState(false)

  /*
  |--------------------------------------------------------------------------
  | Password validation
  |--------------------------------------------------------------------------
  */

  const passwordRules =
    useMemo(
      () => ({
        minLength:
          password.length >= 8,

        uppercase:
          /[A-Z]/.test(
            password,
          ),

        lowercase:
          /[a-z]/.test(
            password,
          ),

        number:
          /\d/.test(
            password,
          ),
      }),
      [password],
    )

  const passwordValid =
    Object.values(
      passwordRules,
    ).every(Boolean)

  const confirmationMatches =
    confirmation.length > 0 &&
    password === confirmation

  const canSubmitReset =
    !reset ||
    (
      passwordValid &&
      confirmationMatches
    )

  /*
  |--------------------------------------------------------------------------
  | Retry timer
  |--------------------------------------------------------------------------
  */

  useEffect(() => {
    const timer =
      window.setInterval(
        () =>
          setRemaining(
            value =>
              Math.max(
                0,
                value - 1,
              ),
          ),
        1000,
      )

    return () =>
      window.clearInterval(
        timer,
      )
  }, [])

  /*
  |--------------------------------------------------------------------------
  | Submit
  |--------------------------------------------------------------------------
  */

  async function submit(
    event:
      FormEvent<HTMLFormElement>,
  ) {
    event.preventDefault()

    if (
      sending ||
      remaining > 0 ||
      done ||
      invalid
    ) {
      return
    }

    setError('')
    setMessage('')

    if (
      reset &&
      !passwordValid
    ) {
      setError(
        'La contraseña no cumple con los requisitos de seguridad.',
      )

      return
    }

    if (
      reset &&
      password !==
      confirmation
    ) {
      setError(
        'Las contraseñas no coinciden.',
      )

      return
    }

    setSending(true)

    try {
      await getCsrfToken()

      const { data } =
        await api.post(
          reset
            ? '/api/auth/reset-password'
            : '/api/auth/forgot-password',
          {
            email:
              email
                .trim()
                .toLowerCase(),

            ...(reset
              ? {
                token:
                  link.get(
                    'token',
                  ),

                password,

                password_confirmation:
                  confirmation,
              }
              : {}),
          },
        )

      setMessage(
        data.message,
      )

      setLocal(
        Boolean(
          data.local_delivery,
        ),
      )

      if (reset) {
        setDone(true)

        setPassword('')
        setConfirmation('')

        setShowPassword(false)
        setShowConfirmation(false)

        window.history
          .replaceState(
            window.history.state,
            '',
            window.location.pathname,
          )

        window.dispatchEvent(
          new CustomEvent(
            'kova:session-expired',
          ),
        )
      } else {
        setRemaining(
          data.retry_after ??
          60,
        )
      }
    } catch (exception) {
      const detail =
        getApiError(
          exception,
        )

      if (
        detail.code ===
        'invalid_reset_token'
      ) {
        setInvalid(true)
      }

      if (
        axios.isAxiosError(
          exception,
        ) &&
        exception.response
          ?.status === 429
      ) {
        const seconds =
          Number(
            exception.response
              .headers[
            'retry-after'
            ] ?? 60,
          )

        setRemaining(
          Number.isFinite(
            seconds,
          )
            ? seconds
            : 60,
        )

        setError(
          'Demasiados intentos. Espera antes de volver a intentarlo.',
        )
      } else {
        setError(
          Object.values(
            detail.errors ??
            {},
          ).flat()[0] ??
          detail.message,
        )
      }
    } finally {
      setSending(false)
    }
  }

  return (
    <AuthLayout>
      <main className="flex min-h-svh items-center justify-center px-6 py-10">
        <section className="w-full max-w-md space-y-6">
          {/* Header */}
          <div className="text-center">
            <h1 className="text-2xl font-semibold tracking-tight">
              {done
                ? 'Contraseña actualizada'
                : reset
                  ? 'Crea una nueva contraseña'
                  : 'Recupera tu cuenta'}
            </h1>

            <p className="mt-2 text-sm text-muted-foreground">
              {reset
                ? 'Tu nueva contraseña protegerá el acceso a Kova.'
                : 'Te enviaremos un enlace para restablecer tu contraseña.'}
            </p>
          </div>

          {/* Success message */}
          {message && (
            <Alert
              role="status"
              aria-live="polite"
            >
              <AlertDescription>
                {message}
              </AlertDescription>
            </Alert>
          )}

          {/* Local mail mode */}
          {local && (
            <Alert>
              <AlertDescription>
                El correo está en
                modo de prueba. El
                enlace se guarda
                localmente y no
                llegará a tu bandeja
                de entrada.
              </AlertDescription>
            </Alert>
          )}

          {/* Error */}
          {(error ||
            invalid) && (
              <Alert variant="destructive">
                <AlertDescription>
                  {error ||
                    'Este enlace está incompleto o no es válido. Solicita uno nuevo.'}
                </AlertDescription>
              </Alert>
            )}

          {!done &&
            !invalid && (
              <form
                onSubmit={
                  submit
                }
                className="space-y-4"
              >
                {/* Email */}
                <Field>
                  <FieldLabel htmlFor="recovery-email">
                    Correo electrónico
                  </FieldLabel>

                  <Input
                    id="recovery-email"
                    type="email"
                    autoComplete="email"
                    maxLength={
                      255
                    }
                    required
                    value={
                      email
                    }
                    readOnly={
                      reset
                    }
                    disabled={
                      sending
                    }
                    onChange={event =>
                      setEmail(
                        event
                          .target
                          .value,
                      )
                    }
                  />
                </Field>

                {/* Reset password */}
                {reset && (
                  <>
                    <Field>
                      <FieldLabel htmlFor="new-password">
                        Nueva contraseña
                      </FieldLabel>

                      <div className="relative">
                        <Input
                          id="new-password"
                          type={
                            showPassword
                              ? 'text'
                              : 'password'
                          }
                          autoComplete="new-password"
                          minLength={
                            8
                          }
                          required
                          value={
                            password
                          }
                          disabled={
                            sending
                          }
                          className="pr-10"
                          aria-describedby="password-rules"
                          aria-invalid={
                            password.length >
                            0 &&
                            !passwordValid
                          }
                          onChange={event =>
                            setPassword(
                              event
                                .target
                                .value,
                            )
                          }
                        />

                        <button
                          type="button"
                          disabled={
                            sending
                          }
                          aria-label={
                            showPassword
                              ? 'Ocultar contraseña'
                              : 'Mostrar contraseña'
                          }
                          aria-pressed={
                            showPassword
                          }
                          className="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 disabled:pointer-events-none disabled:opacity-50"
                          onClick={() =>
                            setShowPassword(
                              value =>
                                !value,
                            )
                          }
                        >
                          {showPassword ? (
                            <EyeOffIcon
                              className="size-4"
                              aria-hidden
                            />
                          ) : (
                            <EyeIcon
                              className="size-4"
                              aria-hidden
                            />
                          )}
                        </button>
                      </div>

                      <div
                        id="password-rules"
                        className="mt-2 grid gap-1.5 sm:grid-cols-2"
                      >
                        <PasswordRule
                          valid={
                            passwordRules.minLength
                          }
                        >
                          Mínimo 8
                          caracteres
                        </PasswordRule>

                        <PasswordRule
                          valid={
                            passwordRules.uppercase
                          }
                        >
                          Una mayúscula
                        </PasswordRule>

                        <PasswordRule
                          valid={
                            passwordRules.lowercase
                          }
                        >
                          Una minúscula
                        </PasswordRule>

                        <PasswordRule
                          valid={
                            passwordRules.number
                          }
                        >
                          Un número
                        </PasswordRule>
                      </div>
                    </Field>

                    {/* Confirmation */}
                    <Field>
                      <FieldLabel htmlFor="confirm-password">
                        Confirma la
                        contraseña
                      </FieldLabel>

                      <div className="relative">
                        <Input
                          id="confirm-password"
                          type={
                            showConfirmation
                              ? 'text'
                              : 'password'
                          }
                          autoComplete="new-password"
                          minLength={
                            8
                          }
                          required
                          value={
                            confirmation
                          }
                          disabled={
                            sending
                          }
                          className="pr-10"
                          aria-invalid={
                            confirmation.length >
                            0 &&
                            !confirmationMatches
                          }
                          aria-describedby="password-confirmation-status"
                          onChange={event =>
                            setConfirmation(
                              event
                                .target
                                .value,
                            )
                          }
                        />

                        <button
                          type="button"
                          disabled={
                            sending
                          }
                          aria-label={
                            showConfirmation
                              ? 'Ocultar contraseña'
                              : 'Mostrar contraseña'
                          }
                          aria-pressed={
                            showConfirmation
                          }
                          className="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 disabled:pointer-events-none disabled:opacity-50"
                          onClick={() =>
                            setShowConfirmation(
                              value =>
                                !value,
                            )
                          }
                        >
                          {showConfirmation ? (
                            <EyeOffIcon
                              className="size-4"
                              aria-hidden
                            />
                          ) : (
                            <EyeIcon
                              className="size-4"
                              aria-hidden
                            />
                          )}
                        </button>
                      </div>

                      {confirmation.length >
                        0 && (
                          <div
                            id="password-confirmation-status"
                            className={[
                              'mt-2 flex items-center gap-1.5 text-xs',
                              confirmationMatches
                                ? 'text-emerald-600 dark:text-emerald-400'
                                : 'text-muted-foreground',
                            ].join(
                              ' ',
                            )}
                          >
                            {confirmationMatches ? (
                              <CheckCircle2Icon
                                className="size-3.5"
                                aria-hidden
                              />
                            ) : (
                              <CircleIcon
                                className="size-3.5"
                                aria-hidden
                              />
                            )}

                            <span>
                              {confirmationMatches
                                ? 'Las contraseñas coinciden'
                                : 'Las contraseñas deben coincidir'}
                            </span>
                          </div>
                        )}
                    </Field>
                  </>
                )}

                {/* Submit */}
                <Button
                  className="w-full"
                  type="submit"
                  disabled={
                    sending ||
                    remaining >
                    0 ||
                    !canSubmitReset
                  }
                >
                  {sending && (
                    <LoaderCircle
                      className="size-4 animate-spin"
                      aria-hidden
                    />
                  )}

                  {sending
                    ? 'Procesando…'
                    : remaining >
                      0
                      ? `Intenta de nuevo en ${remaining} s`
                      : reset
                        ? 'Guardar contraseña'
                        : 'Enviar enlace'}
                </Button>
              </form>
            )}

          {/* Invalid link */}
          {invalid && (
            <Button
              asChild
              className="w-full"
            >
              <Link to="/forgot-password">
                Solicitar otro enlace
              </Link>
            </Button>
          )}

          {/* Login */}
          <Button
            variant={
              done
                ? 'default'
                : 'link'
            }
            asChild
            className="w-full"
          >
            <Link to="/login">
              {done
                ? 'Iniciar sesión'
                : 'Volver al inicio de sesión'}
            </Link>
          </Button>
        </section>
      </main>
    </AuthLayout>
  )
}

/*
|--------------------------------------------------------------------------
| Password rule
|--------------------------------------------------------------------------
*/

function PasswordRule({
  valid,
  children,
}: {
  valid: boolean
  children:
  React.ReactNode
}) {
  return (
    <div
      className={[
        'flex items-center gap-1.5 text-xs transition-colors',
        valid
          ? 'text-emerald-600 dark:text-emerald-400'
          : 'text-muted-foreground',
      ].join(' ')}
    >
      {valid ? (
        <CheckCircle2Icon
          className="size-3.5 shrink-0"
          aria-hidden
        />
      ) : (
        <CircleIcon
          className="size-3.5 shrink-0"
          aria-hidden
        />
      )}

      <span>
        {children}
      </span>
    </div>
  )
}

export function ResetPasswordPage() {
  return (
    <PasswordRecoveryPage
      reset
    />
  )
}