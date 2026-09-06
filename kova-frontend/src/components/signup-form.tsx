import {
  Link,
  useNavigate,
} from '@tanstack/react-router'

import {
  Check,
  Eye,
  EyeOff,
  LoaderCircle,
} from 'lucide-react'

import {
  type FormEvent,
  useMemo,
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
  FieldError,
  FieldGroup,
  FieldLabel,
} from '@/components/ui/field'

import {
  Input,
} from '@/components/ui/input'

import {
  getApiError,
  register,
} from '@/features/auth/api'

import {
  cn,
} from '@/lib/utils'

type RegisterForm = {
  name: string
  business_name: string
  email: string
  password: string
  password_confirmation: string
}

type PasswordRequirement = {
  key: string
  label: string
  valid: boolean
}

const initialForm: RegisterForm = {
  name: '',
  business_name: '',
  email: '',
  password: '',
  password_confirmation: '',
}

export function SignupForm({
  className,
  ...props
}: React.ComponentProps<'div'>) {
  const navigate =
    useNavigate()

  const [
    form,
    setForm,
  ] =
    useState<RegisterForm>(
      initialForm,
    )

  const [
    errors,
    setErrors,
  ] =
    useState<
      Record<
        string,
        string[]
      >
    >({})

  const [
    error,
    setError,
  ] =
    useState('')

  const [
    isSubmitting,
    setIsSubmitting,
  ] =
    useState(false)

  const [
    showPassword,
    setShowPassword,
  ] =
    useState(false)

  const [
    showConfirmation,
    setShowConfirmation,
  ] =
    useState(false)

  /*
  |--------------------------------------------------------------------------
  | Password
  |--------------------------------------------------------------------------
  */

  const requirements =
    useMemo<
      PasswordRequirement[]
    >(
      () => [
        {
          key: 'length',
          label:
            '8 caracteres',
          valid:
            form.password
              .length >= 8,
        },
        {
          key: 'uppercase',
          label:
            'Mayúscula',
          valid:
            /[A-Z]/.test(
              form.password,
            ),
        },
        {
          key: 'lowercase',
          label:
            'Minúscula',
          valid:
            /[a-z]/.test(
              form.password,
            ),
        },
        {
          key: 'number',
          label:
            'Número',
          valid:
            /\d/.test(
              form.password,
            ),
        },
      ],
      [form.password],
    )

  const passwordIsValid =
    requirements.every(
      (item) =>
        item.valid,
    )

  const confirmationStarted =
    form
      .password_confirmation
      .length > 0

  const passwordsMatch =
    confirmationStarted &&
    form.password ===
    form.password_confirmation

  /*
  |--------------------------------------------------------------------------
  | Actions
  |--------------------------------------------------------------------------
  */

  function updateField(
    field:
      keyof RegisterForm,
    value: string,
  ) {
    setForm(
      (current) => ({
        ...current,
        [field]:
          value,
      }),
    )

    setErrors(
      (current) => ({
        ...current,
        [field]: [],
      }),
    )

    setError('')
  }

  async function handleSubmit(
    event:
      FormEvent<HTMLFormElement>,
  ) {
    event.preventDefault()

    setError('')
    setErrors({})

    if (
      !passwordIsValid
    ) {
      setErrors({
        password: [
          'La contraseña no cumple todos los requisitos.',
        ],
      })

      return
    }

    if (
      form.password !==
      form
        .password_confirmation
    ) {
      setErrors({
        password_confirmation:
          [
            'Las contraseñas no coinciden.',
          ],
      })

      return
    }

    setIsSubmitting(true)

    try {
      await register({
        ...form,

        name:
          form.name.trim(),

        business_name:
          form.business_name
            .trim(),

        email:
          form.email
            .trim()
            .toLowerCase(),
      })

      await navigate({
        to:
          '/verify-email',

        replace:
          true,

        state: {
          verificationEmail:
            form.email
              .trim()
              .toLowerCase(),

          justRegistered:
            true,
        },
      })
    } catch (
    exception
    ) {
      const apiError =
        getApiError(
          exception,
        )

      setErrors(
        apiError.errors ??
        {},
      )

      setError(
        apiError.errors
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
        'w-full',
        className,
      )}
      {...props}
    >
      {/*
      |--------------------------------------------------------------------------
      | Brand
      |--------------------------------------------------------------------------
      */}

      <div className="mb-4">
        <img
          src="/brand/kova-logo.png"
          alt="Kova"
          className="h-8 w-auto"
        />
      </div>

      {/*
      |--------------------------------------------------------------------------
      | Header
      |--------------------------------------------------------------------------
      */}

      <div className="mb-5">
        <h1 className="text-2xl font-semibold tracking-tight sm:text-[28px]">
          Crea tu cuenta
        </h1>

        <p className="mt-1.5 text-sm text-muted-foreground">
          Crea tu negocio y comienza a vender con Kova.
        </p>
      </div>

      <form
        onSubmit={
          handleSubmit
        }
        noValidate
      >
        <FieldGroup className="gap-3">
          {error && (
            <Alert variant="destructive">
              <AlertDescription>
                {error}
              </AlertDescription>
            </Alert>
          )}

          {/*
          |--------------------------------------------------------------------------
          | Name
          |--------------------------------------------------------------------------
          */}

          <Field
            className="gap-1.5"
            data-invalid={
              Boolean(
                errors
                  .name?.[0],
              )
            }
          >
            <FieldLabel htmlFor="name">
              Tu nombre
            </FieldLabel>

            <Input
              id="name"
              name="name"
              autoComplete="name"
              placeholder="Ej. Juan Pérez"
              className="h-10"
              value={
                form.name
              }
              onChange={(
                event,
              ) =>
                updateField(
                  'name',
                  event
                    .target
                    .value,
                )
              }
              aria-invalid={
                Boolean(
                  errors
                    .name?.[0],
                )
              }
              disabled={
                isSubmitting
              }
              required
            />

            <FieldError>
              {
                errors
                  .name?.[0]
              }
            </FieldError>
          </Field>

          {/*
          |--------------------------------------------------------------------------
          | Business
          |--------------------------------------------------------------------------
          */}

          <Field
            className="gap-1.5"
            data-invalid={
              Boolean(
                errors
                  .business_name?.[0],
              )
            }
          >
            <FieldLabel htmlFor="business_name">
              Nombre del negocio
            </FieldLabel>

            <Input
              id="business_name"
              name="business_name"
              autoComplete="organization"
              placeholder="Ej. Café Central"
              className="h-10"
              value={
                form.business_name
              }
              onChange={(
                event,
              ) =>
                updateField(
                  'business_name',
                  event
                    .target
                    .value,
                )
              }
              aria-invalid={
                Boolean(
                  errors
                    .business_name?.[0],
                )
              }
              disabled={
                isSubmitting
              }
              required
            />

            <FieldError>
              {
                errors
                  .business_name?.[0]
              }
            </FieldError>
          </Field>

          {/*
          |--------------------------------------------------------------------------
          | Email
          |--------------------------------------------------------------------------
          */}

          <Field
            className="gap-1.5"
            data-invalid={
              Boolean(
                errors
                  .email?.[0],
              )
            }
          >
            <FieldLabel htmlFor="register-email">
              Correo electrónico
            </FieldLabel>

            <Input
              id="register-email"
              name="email"
              type="email"
              autoComplete="email"
              inputMode="email"
              placeholder="tu@negocio.com"
              className="h-10"
              value={
                form.email
              }
              onChange={(
                event,
              ) =>
                updateField(
                  'email',
                  event
                    .target
                    .value,
                )
              }
              aria-invalid={
                Boolean(
                  errors
                    .email?.[0],
                )
              }
              disabled={
                isSubmitting
              }
              required
            />

            <FieldError>
              {
                errors
                  .email?.[0]
              }
            </FieldError>
          </Field>

          {/*
          |--------------------------------------------------------------------------
          | Password
          |--------------------------------------------------------------------------
          */}

          <Field
            className="gap-1.5"
            data-invalid={
              Boolean(
                errors
                  .password?.[0],
              )
            }
          >
            <FieldLabel htmlFor="new-password">
              Contraseña
            </FieldLabel>

            <PasswordInput
              id="new-password"
              name="password"
              value={
                form.password
              }
              visible={
                showPassword
              }
              disabled={
                isSubmitting
              }
              invalid={
                Boolean(
                  errors
                    .password?.[0],
                )
              }
              placeholder="Crea una contraseña"
              onToggle={() =>
                setShowPassword(
                  (current) =>
                    !current,
                )
              }
              onChange={(
                value,
              ) =>
                updateField(
                  'password',
                  value,
                )
              }
            />

            <PasswordRequirements
              requirements={
                requirements
              }
              active={
                form.password
                  .length > 0
              }
            />

            <FieldError>
              {
                errors
                  .password?.[0]
              }
            </FieldError>
          </Field>

          {/*
          |--------------------------------------------------------------------------
          | Confirmation
          |--------------------------------------------------------------------------
          */}

          <Field
            className="gap-1.5"
            data-invalid={
              Boolean(
                errors
                  .password_confirmation?.[0],
              ) ||
              (
                confirmationStarted &&
                !passwordsMatch
              )
            }
          >
            <FieldLabel htmlFor="password_confirmation">
              Confirma tu contraseña
            </FieldLabel>

            <PasswordInput
              id="password_confirmation"
              name="password_confirmation"
              value={
                form
                  .password_confirmation
              }
              visible={
                showConfirmation
              }
              disabled={
                isSubmitting
              }
              invalid={
                Boolean(
                  errors
                    .password_confirmation?.[0],
                ) ||
                (
                  confirmationStarted &&
                  !passwordsMatch
                )
              }
              placeholder="Repite tu contraseña"
              onToggle={() =>
                setShowConfirmation(
                  (current) =>
                    !current,
                )
              }
              onChange={(
                value,
              ) =>
                updateField(
                  'password_confirmation',
                  value,
                )
              }
            />

            {confirmationStarted &&
              passwordsMatch && (
                <div className="flex items-center gap-1.5 text-xs text-emerald-600">
                  <Check className="size-3.5" />

                  Las contraseñas coinciden
                </div>
              )}

            {confirmationStarted &&
              !passwordsMatch &&
              !errors
                .password_confirmation?.[0] && (
                <FieldError>
                  Las contraseñas no coinciden.
                </FieldError>
              )}

            <FieldError>
              {
                errors
                  .password_confirmation?.[0]
              }
            </FieldError>
          </Field>

          {/*
          |--------------------------------------------------------------------------
          | Submit
          |--------------------------------------------------------------------------
          */}

          <Button
            type="submit"
            className="mt-1 h-10 w-full"
            disabled={
              isSubmitting
            }
          >
            {isSubmitting && (
              <LoaderCircle
                className="size-4 animate-spin"
                aria-hidden
              />
            )}

            {isSubmitting
              ? 'Creando tu negocio...'
              : 'Crear cuenta'}
          </Button>

          <p className="text-center text-xs text-muted-foreground">
            ¿Ya tienes una cuenta?{' '}

            <Link
              to="/login"
              className="font-medium text-foreground hover:underline"
            >
              Inicia sesión
            </Link>
          </p>
        </FieldGroup>
      </form>
    </div>
  )
}

/*
|--------------------------------------------------------------------------
| Password input
|--------------------------------------------------------------------------
*/

function PasswordInput({
  id,
  name,
  value,
  visible,
  invalid,
  disabled,
  placeholder,
  onChange,
  onToggle,
}: {
  id: string
  name: string
  value: string
  visible: boolean
  invalid: boolean
  disabled: boolean
  placeholder: string
  onChange:
  (
    value: string,
  ) => void
  onToggle:
  () => void
}) {
  return (
    <div className="relative">
      <Input
        id={id}
        name={name}
        type={
          visible
            ? 'text'
            : 'password'
        }
        autoComplete="new-password"
        className="h-10 pr-10"
        placeholder={
          placeholder
        }
        value={
          value
        }
        onChange={(
          event,
        ) =>
          onChange(
            event.target
              .value,
          )
        }
        aria-invalid={
          invalid
        }
        disabled={
          disabled
        }
        required
      />

      <button
        type="button"
        tabIndex={-1}
        disabled={
          disabled
        }
        onClick={
          onToggle
        }
        className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground transition hover:text-foreground"
        aria-label={
          visible
            ? 'Ocultar contraseña'
            : 'Mostrar contraseña'
        }
      >
        {visible ? (
          <EyeOff className="size-4" />
        ) : (
          <Eye className="size-4" />
        )}
      </button>
    </div>
  )
}

/*
|--------------------------------------------------------------------------
| Password requirements
|--------------------------------------------------------------------------
*/

function PasswordRequirements({
  requirements,
  active,
}: {
  requirements:
  PasswordRequirement[]

  active: boolean
}) {
  return (
    <div className="grid grid-cols-2 gap-x-4 gap-y-1 pt-0.5">
      {requirements.map(
        (
          requirement,
        ) => (
          <div
            key={
              requirement.key
            }
            className={cn(
              'flex items-center gap-1.5 text-[11px] transition-colors',

              active &&
                requirement.valid
                ? 'text-emerald-600'
                : 'text-muted-foreground',
            )}
          >
            <span
              className={cn(
                'flex size-3.5 shrink-0 items-center justify-center rounded-full border',

                active &&
                requirement.valid &&
                'border-emerald-500 bg-emerald-500 text-white',
              )}
            >
              {active &&
                requirement.valid && (
                  <Check className="size-2.5" />
                )}
            </span>

            {
              requirement.label
            }
          </div>
        ),
      )}
    </div>
  )
}