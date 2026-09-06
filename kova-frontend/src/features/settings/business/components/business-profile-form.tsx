import {
    type FormEvent,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react'

import {
    Building2Icon,
    ImageIcon,
    LoaderCircleIcon,
    MailIcon,
    PhoneIcon,
    SaveIcon,
    Trash2Icon,
    UploadCloudIcon,
} from 'lucide-react'

import {
    toast,
} from 'sonner'

import {
    Button,
} from '@/components/ui/button'

import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card'

import {
    Input,
} from '@/components/ui/input'

import {
    Label,
} from '@/components/ui/label'

import type {
    BusinessProfile,
} from '../types'

type BusinessProfileFormProps = {
    profile: BusinessProfile

    isSaving: boolean

    onSave: (
        formData: FormData,
    ) => Promise<BusinessProfile>
}

export function BusinessProfileForm({
    profile,
    isSaving,
    onSave,
}: BusinessProfileFormProps) {
    /*
    |--------------------------------------------------------------------------
    | State
    |--------------------------------------------------------------------------
    */

    const [
        name,
        setName,
    ] =
        useState(
            profile.name,
        )

    const [
        phone,
        setPhone,
    ] =
        useState(
            profile.phone ??
            '',
        )

    const [
        email,
        setEmail,
    ] =
        useState(
            profile.public_email ??
            '',
        )

    const [
        logo,
        setLogo,
    ] =
        useState<File | null>(
            null,
        )

    const [
        removeLogo,
        setRemoveLogo,
    ] =
        useState(false)

    const fileInputRef =
        useRef<HTMLInputElement>(
            null,
        )

    /*
    |--------------------------------------------------------------------------
    | Sync
    |--------------------------------------------------------------------------
    */

    useEffect(() => {
        setName(
            profile.name,
        )

        setPhone(
            profile.phone ??
            '',
        )

        setEmail(
            profile.public_email ??
            '',
        )

        setLogo(null)
        setRemoveLogo(false)
    }, [
        profile,
    ])

    /*
    |--------------------------------------------------------------------------
    | Logo preview
    |--------------------------------------------------------------------------
    */

    const selectedLogoUrl =
        useMemo(
            () => {
                if (!logo) {
                    return null
                }

                return URL.createObjectURL(
                    logo,
                )
            },
            [
                logo,
            ],
        )

    useEffect(
        () => {
            return () => {
                if (
                    selectedLogoUrl
                ) {
                    URL.revokeObjectURL(
                        selectedLogoUrl,
                    )
                }
            }
        },
        [
            selectedLogoUrl,
        ],
    )

    const logoPreview =
        removeLogo
            ? null
            : selectedLogoUrl ??
            profile.logo_url

    /*
    |--------------------------------------------------------------------------
    | Form state
    |--------------------------------------------------------------------------
    */

    const normalizedName =
        name.trim()

    const normalizedPhone =
        phone.trim()

    const normalizedEmail =
        email
            .trim()
            .toLowerCase()

    const isDirty =
        normalizedName !==
        profile.name.trim() ||
        normalizedPhone !==
        (
            profile.phone ??
            ''
        ).trim() ||
        normalizedEmail !==
        (
            profile.public_email ??
            ''
        )
            .trim()
            .toLowerCase() ||
        logo !== null ||
        removeLogo

    const hasRequiredData =
        normalizedName.length >=
        2 &&
        normalizedPhone !==
        '' &&
        normalizedEmail !==
        ''

    const canSave =
        isDirty &&
        hasRequiredData &&
        !isSaving

    /*
    |--------------------------------------------------------------------------
    | Actions
    |--------------------------------------------------------------------------
    */

    function handleSelectLogo(
        file:
            File |
            null,
    ) {
        setLogo(
            file,
        )

        if (file) {
            setRemoveLogo(
                false,
            )
        }
    }

    function handleRemoveLogo() {
        setLogo(null)

        setRemoveLogo(
            true,
        )

        if (
            fileInputRef.current
        ) {
            fileInputRef.current.value =
                ''
        }
    }

    function handleDiscardChanges() {
        setName(
            profile.name,
        )

        setPhone(
            profile.phone ??
            '',
        )

        setEmail(
            profile.public_email ??
            '',
        )

        setLogo(null)
        setRemoveLogo(false)

        if (
            fileInputRef.current
        ) {
            fileInputRef.current.value =
                ''
        }
    }

    async function handleSubmit(
        event:
            FormEvent<HTMLFormElement>,
    ) {
        event.preventDefault()

        if (!canSave) {
            return
        }

        const data =
            new FormData()

        data.append(
            'name',
            normalizedName,
        )

        data.append(
            'phone',
            normalizedPhone,
        )

        data.append(
            'public_email',
            normalizedEmail,
        )

        data.append(
            'remove_logo',
            removeLogo
                ? '1'
                : '0',
        )

        if (logo) {
            data.append(
                'logo',
                logo,
            )
        }

        try {
            const saved =
                await onSave(
                    data,
                )

            setName(
                saved.name,
            )

            setPhone(
                saved.phone ??
                '',
            )

            setEmail(
                saved.public_email ??
                '',
            )

            setLogo(null)
            setRemoveLogo(false)

            if (
                fileInputRef.current
            ) {
                fileInputRef.current.value =
                    ''
            }

            toast.success(
                'Información del negocio guardada.',
            )
        } catch {
            toast.error(
                'No pudimos guardar la información del negocio.',
            )
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    return (
        <form
            onSubmit={
                handleSubmit
            }
            className="space-y-5"
        >
            {/*
            |--------------------------------------------------------------------------
            | Identity
            |--------------------------------------------------------------------------
            */}

            <Card>
                <CardContent className="space-y-7 pt-6">
                    {/*
                    |--------------------------------------------------------------------------
                    | Logo
                    |--------------------------------------------------------------------------
                    */}

                    <div className="grid gap-3 sm:grid-cols-[180px_minmax(0,1fr)] sm:gap-6">
                        <div>
                            <Label>
                                Logo
                            </Label>

                            <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                Se utilizará en tickets y otras áreas de marca.
                            </p>
                        </div>

                        <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
                            <div className="flex size-24 shrink-0 items-center justify-center overflow-hidden rounded-xl border bg-muted/20">
                                {logoPreview ? (
                                    <img
                                        src={
                                            logoPreview
                                        }
                                        alt="Vista previa del logo del negocio"
                                        className="size-full object-contain p-3"
                                    />
                                ) : (
                                    <ImageIcon className="size-6 text-muted-foreground/60" />
                                )}
                            </div>

                            <div className="min-w-0 space-y-3">
                                <input
                                    ref={
                                        fileInputRef
                                    }
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp"
                                    className="sr-only"
                                    disabled={
                                        isSaving
                                    }
                                    onChange={(
                                        event,
                                    ) =>
                                        handleSelectLogo(
                                            event
                                                .target
                                                .files?.[0] ??
                                            null,
                                        )
                                    }
                                />

                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        disabled={
                                            isSaving
                                        }
                                        onClick={() =>
                                            fileInputRef
                                                .current
                                                ?.click()
                                        }
                                    >
                                        <UploadCloudIcon className="size-4" />

                                        {logoPreview
                                            ? 'Cambiar logo'
                                            : 'Subir logo'}
                                    </Button>

                                    {logoPreview && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            disabled={
                                                isSaving
                                            }
                                            onClick={
                                                handleRemoveLogo
                                            }
                                        >
                                            <Trash2Icon className="size-4" />

                                            Quitar
                                        </Button>
                                    )}
                                </div>

                                <p className="text-xs leading-5 text-muted-foreground">
                                    PNG, JPG o WebP · Máximo 2 MB.
                                    Recomendamos una imagen con fondo transparente.
                                </p>

                                {logo && (
                                    <p className="max-w-xs truncate text-xs font-medium text-foreground">
                                        {
                                            logo.name
                                        }
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="border-t" />

                    {/*
                    |--------------------------------------------------------------------------
                    | Business name
                    |--------------------------------------------------------------------------
                    */}

                    <div className="grid gap-3 sm:grid-cols-[180px_minmax(0,1fr)] sm:gap-6">
                        <div>
                            <Label htmlFor="business-name">
                                Nombre del negocio
                            </Label>

                            <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                Nombre público que verán tus clientes.
                            </p>
                        </div>

                        <div className="max-w-lg">
                            <Input
                                id="business-name"
                                value={
                                    name
                                }
                                placeholder="Ej. Kova Café"
                                maxLength={
                                    160
                                }
                                autoComplete="organization"
                                disabled={
                                    isSaving
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setName(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                                required
                            />

                            <p className="mt-1.5 text-xs text-muted-foreground">
                                Este nombre se utilizará como referencia principal del negocio.
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            {/*
            |--------------------------------------------------------------------------
            | Public contact
            |--------------------------------------------------------------------------
            */}

            <Card>
                <CardHeader className="border-b">
                    <div className="flex items-start gap-3">
                        <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted">
                            <PhoneIcon className="size-4 text-muted-foreground" />
                        </div>

                        <div className="min-w-0">
                            <CardTitle className="text-base">
                                Información de contacto
                            </CardTitle>

                            <p className="mt-1 text-sm text-muted-foreground">
                                Datos públicos que pueden mostrarse a tus clientes.
                            </p>
                        </div>
                    </div>
                </CardHeader>

                <CardContent className="pt-6">
                    <div className="grid gap-5 md:grid-cols-2">
                        {/*
                        |--------------------------------------------------------------------------
                        | Phone
                        |--------------------------------------------------------------------------
                        */}

                        <div className="space-y-2">
                            <Label
                                htmlFor="business-phone"
                                className="flex items-center gap-1.5"
                            >
                                <PhoneIcon className="size-3.5 text-muted-foreground" />

                                Teléfono
                            </Label>

                            <Input
                                id="business-phone"
                                type="tel"
                                inputMode="tel"
                                autoComplete="tel"
                                value={
                                    phone
                                }
                                placeholder="442 123 4567"
                                maxLength={
                                    30
                                }
                                disabled={
                                    isSaving
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setPhone(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                                required
                            />

                            <p className="text-xs leading-5 text-muted-foreground">
                                Utiliza un número donde tus clientes puedan comunicarse con el negocio.
                            </p>
                        </div>

                        {/*
                        |--------------------------------------------------------------------------
                        | Email
                        |--------------------------------------------------------------------------
                        */}

                        <div className="space-y-2">
                            <Label
                                htmlFor="business-email"
                                className="flex items-center gap-1.5"
                            >
                                <MailIcon className="size-3.5 text-muted-foreground" />

                                Correo público
                            </Label>

                            <Input
                                id="business-email"
                                type="email"
                                inputMode="email"
                                autoComplete="email"
                                value={
                                    email
                                }
                                placeholder="contacto@negocio.com"
                                maxLength={
                                    160
                                }
                                disabled={
                                    isSaving
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setEmail(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                                required
                            />

                            <p className="text-xs leading-5 text-muted-foreground">
                                Este correo puede aparecer en tickets y comunicaciones públicas.
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            {/*
            |--------------------------------------------------------------------------
            | Actions
            |--------------------------------------------------------------------------
            */}

            <div className="flex flex-col-reverse gap-2 border-t pt-5 sm:flex-row sm:items-center sm:justify-end">
                {isDirty && (
                    <Button
                        type="button"
                        variant="ghost"
                        disabled={
                            isSaving
                        }
                        onClick={
                            handleDiscardChanges
                        }
                    >
                        Descartar cambios
                    </Button>
                )}

                <Button
                    type="submit"
                    disabled={
                        !canSave
                    }
                >
                    {isSaving ? (
                        <LoaderCircleIcon className="size-4 animate-spin" />
                    ) : (
                        <SaveIcon className="size-4" />
                    )}

                    {isSaving
                        ? 'Guardando...'
                        : 'Guardar cambios'}
                </Button>
            </div>
        </form>
    )
}