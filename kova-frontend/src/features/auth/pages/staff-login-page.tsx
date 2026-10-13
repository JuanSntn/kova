import { useState } from 'react'
import {
  useMutation,
  useQuery,
} from '@tanstack/react-query'
import {
  useNavigate,
  useParams,
} from '@tanstack/react-router'
import {
  ArrowRightIcon,
  Building2Icon,
  EyeIcon,
  EyeOffIcon,
  LoaderCircleIcon,
  LockKeyholeIcon,
  ShieldCheckIcon,
} from 'lucide-react'

import BlurText from '@/components/BlurText'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'

import { useSession } from '@/features/auth/session-context'
import type { SessionPayload } from '@/features/auth/session-types'

import {
  getStaffLoginContext,
  staffLogin,
  userApiError,
} from '@/features/settings/users/api'

export function StaffLoginPage() {
  const {
    branchLoginCode,
  } = useParams({
    strict: false,
  }) as {
    branchLoginCode: string
  }

  const navigate = useNavigate()

  const {
    establishSession,
  } = useSession()

  const [
    username,
    setUsername,
  ] = useState('')

  const [
    password,
    setPassword,
  ] = useState('')

  const [
    showPassword,
    setShowPassword,
  ] = useState(false)

  const context = useQuery({
    queryKey: [
      'staff-login',
      branchLoginCode,
    ],

    queryFn: () =>
      getStaffLoginContext(
        branchLoginCode
      ),

    retry: false,
  })

  const login = useMutation({
    mutationFn: () =>
      staffLogin(
        branchLoginCode,
        username.trim(),
        password
      ) as Promise<SessionPayload>,

    onSuccess: (session) => {
      const tenant =
        session.tenants[0]

      if (!tenant) {
        return
      }

      if (
        tenant.initial_branch_id
      ) {
        localStorage.setItem(
          `kova:branch:${tenant.id}`,
          tenant.initial_branch_id
        )
      }

      establishSession(session)

      void navigate({
        to: '/$tenantSlug/dashboard',

        params: {
          tenantSlug:
            tenant.slug,
        },

        replace: true,
      })
    },
  })

  const canSubmit =
    Boolean(
      context.data &&
      username.trim() &&
      password
    ) &&
    !login.isPending

  function handleSubmit(
    event:
      React.FormEvent<HTMLFormElement>
  ) {
    event.preventDefault()

    if (
      !canSubmit ||
      context.isPending ||
      context.isError
    ) {
      return
    }

    login.mutate()
  }

  function handleUsernameChange(
    value: string
  ) {
    if (login.isError) {
      login.reset()
    }

    setUsername(value)
  }

  function handlePasswordChange(
    value: string
  ) {
    if (login.isError) {
      login.reset()
    }

    setPassword(value)
  }

  return (
    <main className="min-h-svh bg-background">
      <div className="grid min-h-svh lg:grid-cols-[minmax(0,1.08fr)_minmax(28rem,0.92fr)]">
        {/* Visual / brand panel */}
        <aside className="relative hidden overflow-hidden border-r bg-zinc-950 text-white lg:flex lg:flex-col">
          {/* Subtle grid texture */}
          <div
            className="
              pointer-events-none
              absolute
              inset-0
              opacity-[0.055]
              [background-image:linear-gradient(to_right,white_1px,transparent_1px),linear-gradient(to_bottom,white_1px,transparent_1px)]
              [background-size:32px_32px]
            "
          />

          {/* Brand */}
          <div className="relative flex items-center gap-3 px-10 py-8 xl:px-14">
            <div className="flex size-9 items-center justify-center rounded-md border border-white/15 bg-white/5 text-sm font-semibold">
              K
            </div>

            <div>
              <p className="text-sm font-semibold tracking-tight">
                Kova
              </p>

              <p className="text-[11px] text-white/45">
                Punto de venta
              </p>
            </div>
          </div>

          {/* Main content */}
          <div className="relative flex flex-1 items-center px-10 xl:px-14">
            <div className="max-w-xl">
              <div className="mb-8 inline-flex items-center gap-2 border-b border-white/10 pb-3 text-[11px] font-medium uppercase tracking-[0.18em] text-white/45">
              
               Acceso operativo
              </div>

              <BlurText
                text="Todo listo para comenzar."
                delay={70}
                animateBy="words"
                direction="top"
                className="max-w-lg text-4xl font-semibold leading-[1.08] tracking-[-0.035em] text-white xl:text-5xl"
              />

              <p className="mt-5 max-w-md text-sm leading-6 text-white/50">
                Ingresa con tus credenciales de personal
                para acceder únicamente a las sucursales
                y funciones asignadas a tu cuenta.
              </p>

              <div className="mt-10 border-l border-white/15 pl-5">
                {context.isPending ? (
                  <div className="space-y-2">
                    <Skeleton className="h-4 w-36 bg-white/10" />
                    <Skeleton className="h-7 w-56 bg-white/10" />
                  </div>
                ) : context.data ? (
                  <>
                    <p className="text-[11px] font-medium uppercase tracking-[0.16em] text-white/35">
                      Estás ingresando a
                    </p>

                    <p className="mt-2 text-lg font-medium tracking-tight text-white">
                      {context.data.business}
                    </p>

                    <p className="mt-1 text-sm text-white/45">
                      {context.data.branch}
                    </p>
                  </>
                ) : (
                  <>
                    <p className="text-[11px] font-medium uppercase tracking-[0.16em] text-white/35">
                      Acceso
                    </p>

                    <p className="mt-2 text-sm text-white/50">
                      Enlace no disponible
                    </p>
                  </>
                )}
              </div>
            </div>
          </div>

          {/* Footer */}
          <div className="relative px-10 py-8 xl:px-14">
            <div className="flex items-center gap-2 text-xs text-white/35">
              <ShieldCheckIcon className="size-4" />

              <span>
                Acceso seguro para personal autorizado
              </span>
            </div>
          </div>
        </aside>

        {/* Login panel */}
        <section className="flex min-h-svh items-center justify-center px-6 py-10 sm:px-10 lg:px-12 xl:px-16">
          <div className="w-full max-w-[420px]">
            {/* Mobile brand */}
            <div className="mb-10 flex items-center gap-3 lg:hidden">
              <div className="flex size-9 items-center justify-center rounded-md bg-foreground text-sm font-semibold text-background">
                K
              </div>

              <div>
                <p className="text-sm font-semibold tracking-tight">
                  Kova
                </p>

                <p className="text-[11px] text-muted-foreground">
                  Punto de venta
                </p>
              </div>
            </div>

            {context.isPending ? (
              <LoginSkeleton />
            ) : context.isError ? (
              <UnavailableState />
            ) : (
              <>
                {/* Branch identity on mobile */}
                <div className="mb-8 border-b pb-6 lg:hidden">
                  <p className="text-xs font-medium uppercase tracking-[0.14em] text-muted-foreground">
                    Acceso de personal
                  </p>

                  <p className="mt-2 text-base font-semibold tracking-tight">
                    {context.data?.business}
                  </p>

                  <p className="mt-1 text-sm text-muted-foreground">
                    {context.data?.branch}
                  </p>
                </div>

                <div className="mb-8">
                  <p className="text-xs font-medium uppercase tracking-[0.14em] text-muted-foreground">
                    Acceso de personal
                  </p>

                  <h1 className="mt-3 text-2xl font-semibold tracking-[-0.025em]">
                    Inicia sesión
                  </h1>

                  <p className="mt-2 text-sm leading-6 text-muted-foreground">
                    Usa las credenciales asignadas por el
                    administrador de tu negocio.
                  </p>
                </div>

                <form
                  className="space-y-5"
                  onSubmit={
                    handleSubmit
                  }
                >
                  {/* Username */}
                  <div className="space-y-2">
                    <Label
                      htmlFor="staff-username"
                      className="text-sm font-medium"
                    >
                      Usuario
                    </Label>

                    <Input
                      id="staff-username"
                      value={username}
                      autoComplete="username"
                      autoCapitalize="none"
                      autoCorrect="off"
                      spellCheck={false}
                      placeholder="Ingresa tu usuario"
                      className="h-11"
                      disabled={
                        login.isPending
                      }
                      onChange={(event) =>
                        handleUsernameChange(
                          event.target
                            .value
                        )
                      }
                    />
                  </div>

                  {/* Password */}
                  <div className="space-y-2">
                    <Label
                      htmlFor="staff-password"
                      className="text-sm font-medium"
                    >
                      Contraseña
                    </Label>

                    <div className="relative">
                      <Input
                        id="staff-password"
                        type={
                          showPassword
                            ? 'text'
                            : 'password'
                        }
                        value={password}
                        autoComplete="current-password"
                        placeholder="Ingresa tu contraseña"
                        className="h-11 pr-11"
                        disabled={
                          login.isPending
                        }
                        onChange={(
                          event
                        ) =>
                          handlePasswordChange(
                            event
                              .target
                              .value
                          )
                        }
                      />

                      <button
                        type="button"
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
                        "
                        disabled={
                          login.isPending
                        }
                        onClick={() =>
                          setShowPassword(
                            (current) =>
                              !current
                          )
                        }
                      >
                        {showPassword ? (
                          <EyeOffIcon className="size-4" />
                        ) : (
                          <EyeIcon className="size-4" />
                        )}
                      </button>
                    </div>
                  </div>

                  {/* Error */}
                  {login.isError ? (
                    <div
                      role="alert"
                      className="flex gap-3 border-l-2 border-destructive bg-destructive/5 px-4 py-3"
                    >
                      <LockKeyholeIcon className="mt-0.5 size-4 shrink-0 text-destructive" />

                      <p className="text-sm leading-5 text-destructive">
                        {userApiError(
                          login.error
                        )}
                      </p>
                    </div>
                  ) : null}

                  {/* Submit */}
                  <Button
                    type="submit"
                    className="h-11 w-full gap-2"
                    disabled={
                      !canSubmit
                    }
                  >
                    {login.isPending ? (
                      <>
                        <LoaderCircleIcon className="size-4 animate-spin" />

                        Iniciando sesión
                      </>
                    ) : (
                      <>
                        Iniciar sesión

                        <ArrowRightIcon className="size-4" />
                      </>
                    )}
                  </Button>
                </form>

                <div className="mt-8 flex items-start gap-2 border-t pt-5 text-xs leading-5 text-muted-foreground">
                  <ShieldCheckIcon className="mt-0.5 size-4 shrink-0" />

                  <p>
                    Este acceso está vinculado a{' '}
                    <span className="font-medium text-foreground">
                      {context.data?.branch}
                    </span>
                    . Sólo podrás ver las sucursales y
                    funciones asignadas a tu usuario.
                  </p>
                </div>
              </>
            )}
          </div>
        </section>
      </div>
    </main>
  )
}

function LoginSkeleton() {
  return (
    <div className="space-y-8">
      <div className="space-y-3">
        <Skeleton className="h-3 w-28" />
        <Skeleton className="h-8 w-44" />
        <Skeleton className="h-4 w-full max-w-xs" />
      </div>

      <div className="space-y-5">
        <div className="space-y-2">
          <Skeleton className="h-4 w-16" />
          <Skeleton className="h-11 w-full" />
        </div>

        <div className="space-y-2">
          <Skeleton className="h-4 w-24" />
          <Skeleton className="h-11 w-full" />
        </div>

        <Skeleton className="h-11 w-full" />
      </div>
    </div>
  )
}

function UnavailableState() {
  return (
    <div className="max-w-sm">
      <div className="mb-6 flex size-10 items-center justify-center rounded-md border bg-muted/40">
        <Building2Icon className="size-5 text-muted-foreground" />
      </div>

      <p className="text-xs font-medium uppercase tracking-[0.14em] text-muted-foreground">
        Acceso de personal
      </p>

      <h1 className="mt-3 text-2xl font-semibold tracking-[-0.025em]">
        Acceso no disponible
      </h1>

      <p className="mt-3 text-sm leading-6 text-muted-foreground">
        Este enlace no está disponible. Solicita al
        administrador del negocio el enlace correcto de
        acceso para tu sucursal.
      </p>
    </div>
  )
}