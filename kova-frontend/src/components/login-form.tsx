import {
  CreditCard,
  Eye,
  EyeOff,
  LoaderCircle,
  LockKeyhole,
  ReceiptText,
  ShieldCheck,
  ShoppingCart,
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

  const normalizedEmail =
    email
      .trim()
      .toLowerCase()

  const canSubmit =
    Boolean(
      normalizedEmail &&
      password,
    ) &&
    !isSubmitting

  function handleEmailChange(
    value: string,
  ) {
    if (error) {
      setError('')
    }

    if (emailError) {
      setEmailError('')
    }

    setEmail(value)
  }

  function handlePasswordChange(
    value: string,
  ) {
    if (error) {
      setError('')
    }

    setPassword(value)
  }

  async function handleSubmit(
    event: FormEvent,
  ) {
    event.preventDefault()

    if (!canSubmit) {
      return
    }

    setError('')
    setEmailError('')
    setIsSubmitting(true)

    try {
      const session =
        await login(
          normalizedEmail,
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
              normalizedEmail,
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
      setIsSubmitting(false)
    }
  }

  return (
    <div
      className={cn(
        'min-h-svh w-full overflow-hidden bg-background',
        className,
      )}
      {...props}
    >
      <div className="grid min-h-svh w-full lg:grid-cols-[46%_54%]">
        {/*
        |--------------------------------------------------------------------------
        | Form
        |--------------------------------------------------------------------------
        */}

        <section className="flex min-h-svh flex-col bg-background">
          {/*
           * Logo
           */}
          <header className="px-6 pt-7 sm:px-10 lg:px-12 lg:pt-9 xl:px-16">
            <Link
              to="/"
              className="inline-flex items-center"
            >
              <img
                src="/brand/kova-logo.png"
                alt="Kova"
                className="h-10 w-auto object-contain"
              />
            </Link>
          </header>

          {/*
           * Form content
           */}
          <div className="flex flex-1 items-center justify-center px-6 py-10 sm:px-10 lg:px-12 xl:px-16">
            <div className="w-full max-w-md">
              <div className="mb-8">
                <div className="mb-4 flex items-center gap-2 text-xs font-medium text-primary">
                 
                  Portal de administración
                </div>

                <h1 className="text-3xl font-semibold tracking-[-0.035em]">
                  Bienvenido a Kova
                </h1>

                <p className="mt-3 max-w-sm text-sm leading-6 text-muted-foreground">
                  Ingresa a tu cuenta para administrar
                  las ventas y operación de tu negocio.
                </p>
              </div>

              <form
                onSubmit={handleSubmit}
              >
                <FieldGroup className="gap-5">
                  {/*
                  |--------------------------------------------------------------------------
                  | General error
                  |--------------------------------------------------------------------------
                  */}

                  {error && (
                    <Alert
                      variant="destructive"
                      className="rounded-lg"
                    >
                      <LockKeyhole className="size-4" />

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
                      autoCapitalize="none"
                      autoCorrect="off"
                      spellCheck={false}
                      placeholder="tu@negocio.com"
                      value={email}
                      disabled={isSubmitting}
                      aria-invalid={
                        Boolean(
                          emailError,
                        )
                      }
                      className="h-11"
                      onChange={(
                        event,
                      ) =>
                        handleEmailChange(
                          event.target.value,
                        )
                      }
                      required
                    />

                    {emailError && (
                      <FieldError>
                        {emailError}
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

                      <Link
                        to="/forgot-password"
                        className="text-xs font-medium text-muted-foreground transition-colors hover:text-primary"
                      >
                        ¿Olvidaste tu contraseña?
                      </Link>
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
                        value={password}
                        disabled={isSubmitting}
                        className="h-11 pr-11"
                        placeholder="Ingresa tu contraseña"
                        onChange={(
                          event,
                        ) =>
                          handlePasswordChange(
                            event.target.value,
                          )
                        }
                        required
                      />

                      <button
                        type="button"
                        disabled={isSubmitting}
                        aria-label={
                          showPassword
                            ? 'Ocultar contraseña'
                            : 'Mostrar contraseña'
                        }
                        className="
                          absolute
                          right-0
                          top-0
                          flex
                          h-11
                          w-11
                          items-center
                          justify-center
                          text-muted-foreground
                          transition-colors
                          hover:text-foreground
                          focus-visible:outline-none
                          focus-visible:ring-2
                          focus-visible:ring-ring
                          focus-visible:ring-offset-2
                          disabled:pointer-events-none
                          disabled:opacity-50
                        "
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
                      checked={remember}
                      disabled={isSubmitting}
                      onCheckedChange={(
                        checked,
                      ) =>
                        setRemember(
                          checked === true,
                        )
                      }
                    />

                    <label
                      htmlFor="remember"
                      className="cursor-pointer select-none text-sm text-muted-foreground"
                    >
                      Mantener mi sesión iniciada
                    </label>
                  </div>

                  {/*
                  |--------------------------------------------------------------------------
                  | Submit
                  |--------------------------------------------------------------------------
                  */}

                  <Button
                    type="submit"
                    size="lg"
                    className="mt-1 h-11 w-full"
                    disabled={!canSubmit}
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
                  | Register
                  |--------------------------------------------------------------------------
                  */}

                  <div className="pt-2 text-center text-sm text-muted-foreground">
                    ¿Aún no tienes cuenta?{' '}

                    <Link
                      to="/register"
                      className="font-medium text-primary underline-offset-4 transition-colors hover:underline"
                    >
                      Crea tu negocio
                    </Link>
                  </div>
                </FieldGroup>
              </form>

              <div className="mt-8 flex items-start gap-2 border-t pt-5 text-xs leading-5 text-muted-foreground">
                <ShieldCheck className="mt-0.5 size-4 shrink-0" />

                <p>
                  Acceso seguro a la administración
                  de tus negocios y sucursales.
                </p>
              </div>
            </div>
          </div>

          <footer className="px-6 pb-7 text-center text-xs text-muted-foreground sm:px-10 lg:px-12 xl:px-16">
            © {new Date().getFullYear()} Kova.
            Todos los derechos reservados.
          </footer>
        </section>

        {/*
        |--------------------------------------------------------------------------
        | POS Showcase
        |--------------------------------------------------------------------------
        */}

        <LoginShowcase />
      </div>
    </div>
  )
}

function LoginShowcase() {
  return (
    <section className="relative hidden min-h-svh overflow-hidden border-l bg-muted/20 lg:flex lg:flex-col">
      {/*
       * Misma decoración ligera que Register.
       * Primary = naranja de Kova.
       */}
      <div className="pointer-events-none absolute -right-40 -top-40 size-[420px] rounded-full bg-primary/8 blur-3xl" />

      <div className="pointer-events-none absolute -bottom-44 left-10 size-[420px] rounded-full bg-primary/5 blur-3xl" />

      <div className="relative z-10 flex h-full flex-1 flex-col px-9 py-7 xl:px-12 xl:py-9">
        {/*
        |--------------------------------------------------------------------------
        | Header
        |--------------------------------------------------------------------------
        */}

        <div className="max-w-xl">
          <div className="flex items-center gap-2 text-xs font-medium text-primary">
            Kova 
          </div>

          <h2 className="mt-4 max-w-lg text-3xl font-semibold leading-tight tracking-tight">
            Cobra rápido. Opera mejor.
          </h2>

          <p className="mt-3 max-w-lg text-sm leading-6 text-muted-foreground">
            Un punto de venta diseñado para mantener
            ventas, productos, tickets y operación bajo control.
          </p>
        </div>

        {/*
        |--------------------------------------------------------------------------
        | POS preview
        |--------------------------------------------------------------------------
        */}

        <div className="flex min-h-0 flex-1 items-center justify-center py-6">
          <div className="w-full max-w-[700px]">
            <div className="overflow-hidden rounded-2xl border bg-background shadow-xl shadow-black/5">
              {/*
               * App bar
               */}
              <div className="flex h-14 items-center justify-between border-b px-5">
                <div className="flex items-center gap-3">
                  <div className="grid size-8 grid-cols-2 gap-0.5 rounded-lg bg-primary p-1.5">
                    <span className="rounded-[2px] bg-primary-foreground" />
                    <span className="rounded-[2px] bg-primary-foreground/70" />
                    <span className="rounded-[2px] bg-primary-foreground/70" />
                    <span className="rounded-[2px] bg-primary-foreground" />
                  </div>

                  <div>
                    <p className="text-xs font-semibold">
                      Kova Caja
                    </p>

                    <p className="text-[9px] text-muted-foreground">
                      Sucursal Centro
                    </p>
                  </div>
                </div>

                <div className="flex items-center gap-2">
                  <span className="size-2 rounded-full bg-emerald-500" />

                  <span className="text-[9px] text-muted-foreground">
                    Caja abierta
                  </span>
                </div>
              </div>

              {/*
               * POS body
               */}
              <div className="grid h-[420px] grid-cols-[minmax(0,1fr)_255px]">
                {/*
                 * Products
                 */}
                <div className="min-w-0 border-r bg-muted/10 p-5">
                  <div className="mb-4 flex items-center justify-between">
                    <div>
                      <p className="text-[9px] font-medium uppercase tracking-wider text-muted-foreground">
                        Productos
                      </p>

                      <p className="mt-1 text-sm font-semibold">
                        Nueva venta
                      </p>
                    </div>

                    <div className="flex size-8 items-center justify-center rounded-lg bg-primary/10">
                      <ShoppingCart className="size-4 text-primary" />
                    </div>
                  </div>

                  <div className="grid grid-cols-3 gap-2.5">
                    <ProductTile
                      name="Americano"
                      price="$45"
                    />

                    <ProductTile
                      name="Latte"
                      price="$58"
                    />

                    <ProductTile
                      name="Capuccino"
                      price="$62"
                    />

                    <ProductTile
                      name="Cold Brew"
                      price="$70"
                    />

                    <ProductTile
                      name="Croissant"
                      price="$48"
                    />

                    <ProductTile
                      name="Sandwich"
                      price="$95"
                    />
                  </div>

                  <div className="mt-5 grid grid-cols-3 gap-2.5">
                    <MiniStat
                      label="Ventas hoy"
                      value="72"
                    />

                    <MiniStat
                      label="Ingresos"
                      value="$8,450"
                    />

                    <MiniStat
                      label="Ticket promedio"
                      value="$117"
                    />
                  </div>
                </div>

                {/*
                 * Cart
                 */}
                <div className="flex min-w-0 flex-col bg-background">
                  <div className="border-b px-4 py-4">
                    <div className="flex items-center gap-2">
                      <ReceiptText className="size-4 text-primary" />

                      <p className="text-xs font-semibold">
                        Venta #0072
                      </p>
                    </div>
                  </div>

                  <div className="flex-1 space-y-4 p-4">
                    <CartRow
                      quantity="2"
                      name="Latte"
                      amount="$116"
                    />

                    <CartRow
                      quantity="1"
                      name="Croissant"
                      amount="$48"
                    />

                    <CartRow
                      quantity="1"
                      name="Americano"
                      amount="$45"
                    />
                  </div>

                  <div className="border-t p-4">
                    <div className="mb-4 flex items-end justify-between">
                      <span className="text-xs text-muted-foreground">
                        Total
                      </span>

                      <span className="text-2xl font-semibold tracking-tight">
                        $209
                      </span>
                    </div>

                    <div className="flex h-10 items-center justify-center gap-2 rounded-lg bg-primary text-xs font-medium text-primary-foreground">
                      <CreditCard className="size-4" />

                      Cobrar $209
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div className="mt-4">
              <p className="text-sm font-medium">
                Creado para la operación diaria.
              </p>

              <p className="mt-1 text-xs leading-5 text-muted-foreground">
                Menos pasos para cobrar. Más control
                sobre lo que pasa en tu negocio.
              </p>
            </div>
          </div>
        </div>
      </div>
    </section>
  )
}

function ProductTile({
  name,
  price,
}: {
  name: string
  price: string
}) {
  return (
    <div className="flex min-h-[88px] flex-col justify-between rounded-xl border bg-background p-3 shadow-sm">
      <div className="flex size-7 items-center justify-center rounded-lg bg-primary/10">
        <ShoppingCart className="size-3.5 text-primary" />
      </div>

      <div>
        <p className="truncate text-[10px] font-medium">
          {name}
        </p>

        <p className="mt-0.5 text-[9px] font-semibold text-primary">
          {price}
        </p>
      </div>
    </div>
  )
}

function MiniStat({
  label,
  value,
}: {
  label: string
  value: string
}) {
  return (
    <div className="rounded-xl border bg-background px-3 py-3">
      <p className="text-[8px] text-muted-foreground">
        {label}
      </p>

      <p className="mt-1 text-sm font-semibold tracking-tight">
        {value}
      </p>
    </div>
  )
}

function CartRow({
  quantity,
  name,
  amount,
}: {
  quantity: string
  name: string
  amount: string
}) {
  return (
    <div className="flex items-center gap-3">
      <div className="flex size-7 shrink-0 items-center justify-center rounded-md bg-muted text-[9px] font-semibold">
        {quantity}×
      </div>

      <p className="min-w-0 flex-1 truncate text-[10px] font-medium">
        {name}
      </p>

      <span className="text-[10px] font-semibold">
        {amount}
      </span>
    </div>
  )
}
