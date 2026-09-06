import {
  Eye,
  EyeOff,
  LoaderCircle,
} from 'lucide-react'
import {
  type ComponentProps,
  type FormEvent,
  useState,
} from 'react'
import {
  Link,
  useNavigate,
} from '@tanstack/react-router'

import {
  Alert,
  AlertDescription,
} from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Field,
  FieldError,
  FieldGroup,
  FieldLabel,
} from '@/components/ui/field'
import { Input } from '@/components/ui/input'

import {
  getApiError,
  login,
} from '@/features/auth/api'
import { useSession } from '@/features/auth/session-context'
import { cn } from '@/lib/utils'

export function LoginForm({
  className,
  ...props
}: ComponentProps<'div'>) {
  const navigate =
    useNavigate()

  const {
    establishSession,
  } = useSession()

  const [
    email,
    setEmail,
  ] = useState('')

  const [
    password,
    setPassword,
  ] = useState('')

  const [
    remember,
    setRemember,
  ] = useState(false)

  const [
    showPassword,
    setShowPassword,
  ] = useState(false)

  const [
    isSubmitting,
    setIsSubmitting,
  ] = useState(false)

  const [
    error,
    setError,
  ] = useState('')

  const [
    emailError,
    setEmailError,
  ] = useState('')

  async function handleSubmit(
    event: FormEvent,
  ) {
    event.preventDefault()

    setError('')
    setEmailError('')
    setIsSubmitting(true)

    try {
      const session =
        await login(
          email,
          password,
          remember,
        )

      const firstTenant =
        session.tenants[0]

      establishSession(
        session,
      )

      if (firstTenant) {
        await navigate({
          to: '/$tenantSlug/dashboard',

          params: {
            tenantSlug:
              firstTenant.slug,
          },

          replace: true,
        })

        return
      }

      await navigate({
        to: '/login',
        replace: true,
      })
    } catch (exception) {
      const apiError =
        getApiError(
          exception,
        )

      if (
        apiError.code ===
        'email_unverified'
      ) {
        await navigate({
          to: '/verify-email',

          state: {
            verificationEmail:
              apiError.email ??
              email,
          },
        })

        return
      }

      setEmailError(
        apiError.errors
          ?.email?.[0] ??
        '',
      )

      setError(
        apiError.errors?.email
          ? ''
          : apiError.message,
      )
    } finally {
      setIsSubmitting(
        false,
      )
    }
  }

  return (
    <div
      className={cn(
        'h-full w-full overflow-hidden bg-background',
        className,
      )}
      {...props}
    >
      <div className="grid h-full w-full min-w-0 lg:grid-cols-[1.15fr_0.85fr]">

        {/*
                |--------------------------------------------------------------------------
                | Panel izquierdo
                |--------------------------------------------------------------------------
                */}

        <section className="relative hidden h-full min-w-0 overflow-hidden bg-neutral-950 lg:flex lg:flex-col">
          {/*
                     * Puedes cambiar el fondo si quieres.
                     */}
          <div className="absolute inset-0 bg-gradient-to-br from-white/[0.03] via-transparent to-white/[0.06]" />

          {/*
                     * Decoración
                     */}
          <div className="absolute -left-40 top-1/4 size-[420px] rounded-full bg-white/[0.03] blur-3xl" />

          <div className="absolute -right-24 top-0 size-[380px] rounded-full bg-white/[0.03] blur-3xl" />

          {/*
                     * Contenido
                     */}
          <div className="relative z-10 flex h-full flex-1 flex-col px-12 py-12 xl:px-20 xl:py-16">


            {/*
                         * Texto principal
                         */}
            <div className="mt-20 max-w-2xl xl:mt-24">
              <h1 className="text-5xl font-semibold tracking-tight text-white xl:text-6xl xl:leading-[1.08]">
                Todo tu negocio,
                <br />
                en un solo lugar.
              </h1>

              <p className="mt-7 max-w-xl text-lg leading-8 text-white/65">
                Administra ventas,
                productos, inventario,
                mesas y operaciones
                desde una plataforma
                diseñada para simplificar
                tu día.
              </p>
            </div>

            {/*
                         * Imagen
                         *
                         * Coloca tu imagen en:
                         * public/images/login-cover.png
                         */}
            <div className="relative mt-auto flex min-h-[340px] items-end justify-end">
              <div className="absolute bottom-0 right-0 w-[78%] xl:w-[72%]">
                <img
                  src="/images/login-cover.png"
                  alt="Kova POS"
                  className="max-h-[440px] w-full object-contain object-bottom"
                />
              </div>
            </div>
          </div>
        </section>

        {/*
                |--------------------------------------------------------------------------
                | Panel derecho
                |--------------------------------------------------------------------------
                */}

        <main className="flex h-full min-w-0 flex-col overflow-y-auto bg-background">

          {/*
                     * Logo
                     */}
          <div className="px-6 pt-8 sm:px-10 lg:px-14 lg:pt-10">
            <Link
              to="/"
              className="inline-flex items-center gap-3"
            >
              <img
                src="/brand/kova-logo.png"
                alt="Kova"
                className="h-10 w-auto object-contain"
              />

              <div className="leading-tight">
                <p className="text-lg font-semibold tracking-tight text-foreground">
                  Kova Portal
                </p>

                <p className="text-xs text-muted-foreground">
                  Administración
                </p>
              </div>
            </Link>
          </div>

          {/*
                     * Formulario centrado
                     */}
          <div className="flex flex-1 items-center px-6 py-12 sm:px-10 lg:px-14">
            <div className="mx-auto w-full max-w-md">
              <div className="mb-9">
                <h2 className="text-2xl font-semibold tracking-tight">
                  Inicia sesión
                </h2>

                <p className="mt-2 text-sm leading-6 text-muted-foreground">
                  Ingresa tus datos
                  para acceder a tu
                  negocio.
                </p>
              </div>

              <form
                onSubmit={
                  handleSubmit
                }
              >
                <FieldGroup className="gap-5">

                  {/*
                                    |--------------------------------------------------------------------------
                                    | Error general
                                    |--------------------------------------------------------------------------
                                    */}

                  {error && (
                    <Alert variant="destructive">
                      <AlertDescription>
                        {error}
                      </AlertDescription>
                    </Alert>
                  )}

                  {/*
                                    |--------------------------------------------------------------------------
                                    | Email
                                    |--------------------------------------------------------------------------
                                    */}

                  <Field>
                    <FieldLabel htmlFor="email">
                      Correo electrónico
                    </FieldLabel>

                    <Input
                      id="email"
                      name="email"
                      type="email"
                      autoComplete="email"
                      inputMode="email"
                      placeholder="tu@negocio.com"
                      value={
                        email
                      }
                      disabled={
                        isSubmitting
                      }
                      aria-invalid={
                        Boolean(
                          emailError,
                        )
                      }
                      className="h-11"
                      onChange={(
                        event,
                      ) =>
                        setEmail(
                          event.target.value,
                        )
                      }
                      required
                    />

                    {emailError && (
                      <FieldError>
                        {
                          emailError
                        }
                      </FieldError>
                    )}
                  </Field>

                  {/*
                                    |--------------------------------------------------------------------------
                                    | Password
                                    |--------------------------------------------------------------------------
                                    */}

                  <Field>
                    <div className="flex items-center justify-between gap-4">
                      <FieldLabel htmlFor="password">
                        Contraseña
                      </FieldLabel>

                      {/*
                                             * Si luego tienes ruta
                                             * de recuperación puedes
                                             * habilitar esto.
                                             */}
                      {/*
                                            <Link
                                                to="/forgot-password"
                                                className="text-xs font-medium text-muted-foreground hover:text-foreground"
                                            >
                                                ¿Olvidaste tu contraseña?
                                            </Link>
                                            */}
                    </div>

                    <div className="relative">
                      <Input
                        id="password"
                        name="password"
                        type={
                          showPassword
                            ? 'text'
                            : 'password'
                        }
                        autoComplete="current-password"
                        value={
                          password
                        }
                        disabled={
                          isSubmitting
                        }
                        className="h-11 pr-11"
                        placeholder="Ingresa tu contraseña"
                        onChange={(
                          event,
                        ) =>
                          setPassword(
                            event.target.value,
                          )
                        }
                        required
                      />

                      <button
                        type="button"
                        disabled={
                          isSubmitting
                        }
                        aria-label={
                          showPassword
                            ? 'Ocultar contraseña'
                            : 'Mostrar contraseña'
                        }
                        className="absolute right-3 top-1/2 flex size-6 -translate-y-1/2 items-center justify-center text-muted-foreground transition-colors hover:text-foreground disabled:pointer-events-none disabled:opacity-50"
                        onClick={() =>
                          setShowPassword(
                            (current) =>
                              !current,
                          )
                        }
                      >
                        {showPassword ? (
                          <EyeOff className="size-4" />
                        ) : (
                          <Eye className="size-4" />
                        )}
                      </button>
                    </div>
                  </Field>

                  {/*
                                    |--------------------------------------------------------------------------
                                    | Remember
                                    |--------------------------------------------------------------------------
                                    */}

                  <div className="flex items-center gap-2.5">
                    <Checkbox
                      id="remember"
                      checked={
                        remember
                      }
                      disabled={
                        isSubmitting
                      }
                      onCheckedChange={(
                        checked,
                      ) =>
                        setRemember(
                          checked ===
                          true,
                        )
                      }
                    />

                    <label
                      htmlFor="remember"
                      className="cursor-pointer text-sm text-muted-foreground"
                    >
                      Mantener mi sesión
                      iniciada
                    </label>
                  </div>

                  {/*
                                    |--------------------------------------------------------------------------
                                    | Login
                                    |--------------------------------------------------------------------------
                                    */}

                  <Button
                    type="submit"
                    size="lg"
                    className="mt-1 h-11 w-full"
                    disabled={
                      isSubmitting
                    }
                  >
                    {isSubmitting && (
                      <LoaderCircle className="size-4 animate-spin" />
                    )}

                    {isSubmitting
                      ? 'Iniciando sesión...'
                      : 'Iniciar sesión'}
                  </Button>

                  {/*
                                    |--------------------------------------------------------------------------
                                    | Registro
                                    |--------------------------------------------------------------------------
                                    */}

                  <div className="pt-2 text-center text-sm text-muted-foreground">
                    ¿Aún no tienes cuenta?{' '}

                    <Link
                      to="/register"
                      className="font-medium text-foreground underline-offset-4 transition-colors hover:underline"
                    >
                      Crea tu negocio
                    </Link>
                  </div>
                </FieldGroup>
              </form>
            </div>
          </div>

          {/*
                    |--------------------------------------------------------------------------
                    | Footer
                    |--------------------------------------------------------------------------
                    */}

          <div className="px-6 pb-7 text-center text-xs text-muted-foreground sm:px-10 lg:px-14">
            © {new Date().getFullYear()} Kova.
            Todos los derechos reservados.
          </div>
        </main>
      </div>
    </div>
  )
}
