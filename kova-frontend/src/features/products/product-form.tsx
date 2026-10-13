import { ProductInventory } from '@/features/inventory/product-inventory'
import {
    ImageIcon,
    UploadIcon,
    XIcon,
} from 'lucide-react'
import {
    useEffect,
    useMemo,
    useState,
    type ChangeEvent,
    type FormEvent,
} from 'react'
import { useQuery } from '@tanstack/react-query'

import { Button } from '@/components/ui/button'
import { CatalogCombobox } from '@/components/ui/catalog-combobox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select'
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'

import {
    createProduct,
    getApiErrorMessage,
    updateProduct,
} from './api'

import type {
    Category,
    CreateProductPayload,
    Product,
} from './types'
import type {
    Tax,
} from '@/features/settings/taxes/types'
import { getTaxObjects, searchProductServices, searchUnits } from '@/features/fiscal/sat-catalog-api'

type ProductFormProps = {
    open: boolean
    product?: Product | null
    categories: Category[]
    taxes: Tax[]

    onOpenChange: (
        open: boolean
    ) => void

    onSaved: (
        product: Product
    ) => void
}

const MAX_IMAGE_SIZE =
    5 * 1024 * 1024

const ALLOWED_IMAGE_TYPES = [
    'image/jpeg',
    'image/png',
    'image/webp',
]

export function ProductForm({
    open,
    product,
    categories,
    taxes,
    onOpenChange,
    onSaved,
}: ProductFormProps) {
    const isEditing =
        Boolean(product)

    const [
        name,
        setName,
    ] = useState('')

    const [
        categoryId,
        setCategoryId,
    ] = useState('')

    const [
        taxId,
        setTaxId,
    ] = useState('default')

    const [preparationStation, setPreparationStation] = useState<'kitchen' | 'bar' | 'none'>('kitchen')

    const [satProductServiceCode, setSatProductServiceCode] = useState('')
    const [satUnitCode, setSatUnitCode] = useState('')
    const [satTaxObjectCode, setSatTaxObjectCode] = useState('')
    const taxObjects = useQuery({ queryKey: ['sat-tax-objects'], queryFn: getTaxObjects, retry: false })

    const [
        price,
        setPrice,
    ] = useState('')

    const [
        description,
        setDescription,
    ] = useState('')

    /*
     * Archivo que enviaremos
     * al backend.
     */
    const [
        image,
        setImage,
    ] = useState<File | null>(
        null,
    )

    /*
     * URL únicamente para mostrar
     * la vista previa.
     *
     * Puede ser:
     * - image_path existente
     * - URL temporal creada desde File
     */
    const [
        imagePreview,
        setImagePreview,
    ] = useState<string | null>(
        null,
    )

    const [
        isActive,
        setIsActive,
    ] = useState(true)

    const [
        sortOrder,
        setSortOrder,
    ] = useState('0')

    const [
        saving,
        setSaving,
    ] = useState(false)

    const [
        error,
        setError,
    ] = useState<string | null>(
        null,
    )

    /*
     * Para editar permitimos mostrar
     * la categoría actual aunque haya
     * sido desactivada.
     */
    const availableCategories =
        useMemo(() => {
            return categories.filter(
                (category) =>
                    category.can_use ||
                    category.id ===
                    product?.category.id,
            )
        }, [
            categories,
            product,
        ])

    /*
     * Cargar información al abrir
     * el Sheet.
     */
    useEffect(() => {
        if (!open) {
            return
        }

        // eslint-disable-next-line react-hooks/set-state-in-effect
        setName(
            product?.name ?? '',
        )

        setCategoryId(
            product?.category.id ??
            availableCategories[0]
                ?.id ??
            '',
        )

        setTaxId(
            product?.tax.mode === 'specific' &&
            product.tax.id
                ? product.tax.id
                : 'default',
        )

        setPreparationStation(product?.preparation_station ?? 'kitchen')

        setSatProductServiceCode(product?.fiscal.product_service_code ?? '')
        setSatUnitCode(product?.fiscal.unit_code ?? '')
        setSatTaxObjectCode(product?.fiscal.tax_object_code ?? '')

        setPrice(
            product?.price
                ? String(
                    product.price,
                )
                : '',
        )

        setDescription(
            product?.description ?? '',
        )

        /*
         * Un archivo nuevo todavía
         * no existe al abrir.
         */
        setImage(null)

        /*
         * Si estamos editando y ya
         * existe imagen, mostramos
         * la URL recibida por API.
         */
        setImagePreview(
            product?.image_path ??
            null,
        )

        setIsActive(
            product?.is_active ??
            true,
        )

        setSortOrder(
            String(
                product?.sort_order ??
                0,
            ),
        )

        setError(null)
    }, [
        open,
        product,
        availableCategories,
    ])

    /*
     * Limpiar URLs temporales
     * creadas con createObjectURL.
     */
    useEffect(() => {
        return () => {
            if (
                imagePreview?.startsWith(
                    'blob:',
                )
            ) {
                URL.revokeObjectURL(
                    imagePreview,
                )
            }
        }
    }, [imagePreview])

    function handleImageChange(
        event: ChangeEvent<HTMLInputElement>,
    ) {
        const file =
            event.target.files?.[0]

        if (!file) {
            return
        }

        /*
         * Validar formato también
         * en frontend.
         *
         * Backend sigue siendo
         * la validación definitiva.
         */
        if (
            !ALLOWED_IMAGE_TYPES.includes(
                file.type,
            )
        ) {
            setError(
                'La imagen debe ser JPG, PNG o WebP.',
            )

            event.target.value =
                ''

            return
        }

        /*
         * Máximo 5 MB.
         */
        if (
            file.size >
            MAX_IMAGE_SIZE
        ) {
            setError(
                'La imagen no puede superar los 5 MB.',
            )

            event.target.value =
                ''

            return
        }

        /*
         * Liberar preview temporal
         * anterior si existía.
         */
        if (
            imagePreview?.startsWith(
                'blob:',
            )
        ) {
            URL.revokeObjectURL(
                imagePreview,
            )
        }

        const preview =
            URL.createObjectURL(
                file,
            )

        setImage(file)
        setImagePreview(preview)
        setError(null)
    }

    function handleClearNewImage() {
        /*
         * Si solo seleccionamos una
         * imagen nueva, la descartamos.
         */
        if (
            imagePreview?.startsWith(
                'blob:',
            )
        ) {
            URL.revokeObjectURL(
                imagePreview,
            )
        }

        setImage(null)

        /*
         * Al editar volvemos a mostrar
         * la imagen original.
         *
         * Al crear queda vacío.
         */
        setImagePreview(
            product?.image_path ??
            null,
        )
    }

    async function handleSubmit(
        event: FormEvent<HTMLFormElement>,
    ) {
        event.preventDefault()

        const normalizedName =
            name.trim()

        if (!normalizedName) {
            setError(
                'El nombre del producto es obligatorio.',
            )

            return
        }

        if (!categoryId) {
            setError(
                'Selecciona una categoría.',
            )

            return
        }

        const parsedPrice =
            Number(price)

        if (
            !Number.isFinite(
                parsedPrice,
            ) ||
            parsedPrice < 0
        ) {
            setError(
                'Ingresa un precio válido.',
            )

            return
        }

        const payload: CreateProductPayload =
        {
            name:
                normalizedName,

            category_id:
                categoryId,

            tax_id:
                taxId === 'default'
                    ? null
                    : taxId,

            price:
                parsedPrice,

            description:
                description.trim()
                    ? description.trim()
                    : null,

            /*
             * Aquí agregamos el File.
             */
            image,

            is_active:
                isActive,

            preparation_station: preparationStation,

            sat_product_service_code: satProductServiceCode.trim() || null,
            sat_unit_code: satUnitCode || null,
            sat_tax_object_code: satTaxObjectCode || null,
        }

        if (isEditing) {
            const parsedSortOrder =
                Number(sortOrder)

            if (
                !Number.isInteger(
                    parsedSortOrder,
                ) ||
                parsedSortOrder < 0
            ) {
                setError(
                    'El orden debe ser un número entero igual o mayor a 0.',
                )

                return
            }

            payload.sort_order =
                parsedSortOrder
        }

        setSaving(true)
        setError(null)

        try {
            const savedProduct =
                isEditing && product
                    ? await updateProduct(
                        product.id,
                        payload,
                    )
                    : await createProduct(
                        payload,
                    )

            onSaved(
                savedProduct,
            )

            onOpenChange(false)
        } catch (requestError) {
            setError(
                getApiErrorMessage(
                    requestError,
                ),
            )
        } finally {
            setSaving(false)
        }
    }

    return (
        <Sheet
            open={open}
            onOpenChange={
                onOpenChange
            }
        >
            <SheetContent
                side="right"
                className="w-full sm:max-w-xl"
            >
                <form
                    onSubmit={
                        handleSubmit
                    }
                    className="flex h-full flex-col"
                >
                    <SheetHeader>
                        <SheetTitle>
                            {isEditing
                                ? 'Editar producto'
                                : 'Nuevo producto'}
                        </SheetTitle>

                        <SheetDescription>
                            {isEditing
                                ? 'Modifica los datos del producto.'
                                : 'Agrega un nuevo producto a tu menú.'}
                        </SheetDescription>
                    </SheetHeader>

                    <div className="min-h-0 flex-1 space-y-6 overflow-y-auto px-4 py-6">



                        {/*
                        |--------------------------------------------------------------------------
                        | Nombre
                        |--------------------------------------------------------------------------
                        */}

                        <div className="space-y-2">
                            <Label htmlFor="product-name">
                                Nombre
                            </Label>

                            <Input
                                id="product-name"
                                value={name}
                                maxLength={160}
                                disabled={
                                    saving
                                }
                                placeholder="Ej. Hamburguesa clásica"
                                onChange={(
                                    event,
                                ) =>
                                    setName(
                                        event.target
                                            .value,
                                    )
                                }
                            />
                        </div>

                        {/*
                        |--------------------------------------------------------------------------
                        | Categoría
                        |--------------------------------------------------------------------------
                        */}

                        <div className="space-y-2">
                            <Label>
                                Categoría
                            </Label>

                            <Select
                                value={
                                    categoryId
                                }
                                disabled={
                                    saving
                                }
                                onValueChange={
                                    setCategoryId
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Selecciona una categoría" />
                                </SelectTrigger>

                                <SelectContent>
                                    {availableCategories.map(
                                        (category) => (
                                            <SelectItem
                                                key={
                                                    category.id
                                                }
                                                value={
                                                    category.id
                                                }
                                            >
                                                {
                                                    category.name
                                                }

                                                {!category.is_active
                                                    ? ' (inactiva)'
                                                    : ''}
                                            </SelectItem>
                                        ),
                                    )}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-2">
                            <Label>Área de preparación</Label>
                            <Select
                                value={preparationStation}
                                disabled={saving}
                                onValueChange={(value) => setPreparationStation(value as 'kitchen' | 'bar' | 'none')}
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="kitchen">Cocina</SelectItem>
                                    <SelectItem value="bar">Barra</SelectItem>
                                    <SelectItem value="none">Sin preparación</SelectItem>
                                </SelectContent>
                            </Select>
                            <p className="text-xs leading-5 text-muted-foreground">
                                Al cobrar, Kova enviará este producto a la comanda del área seleccionada.
                            </p>
                        </div>

                        <details className="group rounded-lg border bg-muted/20">
                            <summary className="cursor-pointer list-none px-4 py-3 text-sm font-medium marker:hidden">
                                Datos fiscales del producto
                                <span className="ml-2 text-xs font-normal text-muted-foreground">
                                    Opcional
                                </span>
                            </summary>

                            <div className="grid gap-4 border-t px-4 py-4 sm:grid-cols-2">
                                <div className="space-y-2 sm:col-span-2">
                                    <Label htmlFor="sat-product-service-code">
                                        Clave de producto o servicio SAT
                                    </Label>
                                    <CatalogCombobox value={satProductServiceCode} disabled={saving} allowEmpty placeholder="Buscar clave o descripción…" search={searchProductServices} onValueChange={setSatProductServiceCode} />
                                </div>

                                <div className="space-y-2">
                                    <Label>Clave de unidad SAT</Label>
                                    <CatalogCombobox value={satUnitCode} label={product?.fiscal.unit_code === satUnitCode ? product.fiscal.unit_name : null} disabled={saving} allowEmpty placeholder="Buscar unidad…" search={searchUnits} onValueChange={setSatUnitCode} />
                                </div>

                                <div className="space-y-2 sm:col-span-2">
                                    <Label>Objeto de impuesto SAT</Label>
                                    <Select value={satTaxObjectCode || 'default'} disabled={saving} onValueChange={(value) => setSatTaxObjectCode(value === 'default' ? '' : value)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="default">Usar predeterminado</SelectItem>
                                            {taxObjects.data?.data.map((entry) => <SelectItem key={entry.code} value={entry.code}>{entry.code} · {entry.description}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <p className="text-xs leading-5 text-muted-foreground sm:col-span-2">
                                    Si dejas estos campos vacíos, Kova utilizará los valores configurados en Facturación fiscal.
                                </p>
                            </div>
                        </details>

                        {/*
                        |--------------------------------------------------------------------------
                        | Impuesto
                        |--------------------------------------------------------------------------
                        */}

                        <div className="space-y-2">
                            <Label>
                                Impuesto
                            </Label>

                            <Select
                                value={
                                    taxId
                                }
                                disabled={
                                    saving
                                }
                                onValueChange={
                                    setTaxId
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Selecciona un impuesto" />
                                </SelectTrigger>

                                <SelectContent>
                                    <SelectItem value="default">
                                        Usar predeterminado
                                    </SelectItem>

                                    {taxes
                                        .filter(
                                            (tax) =>
                                                tax.is_active ||
                                                tax.id ===
                                                product?.tax.id,
                                        )
                                        .map(
                                            (tax) => (
                                                <SelectItem
                                                    key={
                                                        tax.id
                                                    }
                                                    value={
                                                        tax.id
                                                    }
                                                >
                                                    {tax.name}
                                                    {!tax.is_active
                                                        ? ' (inactivo)'
                                                        : ''}
                                                </SelectItem>
                                            ),
                                        )}
                                </SelectContent>
                            </Select>
                        </div>

                        {/*
                        |--------------------------------------------------------------------------
                        | Precio
                        |--------------------------------------------------------------------------
                        */}

                        <div className="space-y-2">
                            <Label htmlFor="product-price">
                                Precio
                            </Label>

                            <div className="relative">
                                <span className="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">
                                    $
                                </span>

                                <Input
                                    id="product-price"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value={price}
                                    disabled={
                                        saving
                                    }
                                    className="pl-7"
                                    placeholder="0.00"
                                    onChange={(
                                        event,
                                    ) =>
                                        setPrice(
                                            event.target
                                                .value,
                                        )
                                    }
                                />
                            </div>

                            <p className="text-xs text-muted-foreground">
                                El precio capturado es el precio final al cliente e incluye los impuestos aplicables.
                            </p>
                        </div>

                        {/*
                        |--------------------------------------------------------------------------
                        | Descripción
                        |--------------------------------------------------------------------------
                        */}

                        <div className="space-y-2">
                            <Label htmlFor="product-description">
                                Descripción
                            </Label>

                            <Textarea
                                id="product-description"
                                value={
                                    description
                                }
                                disabled={
                                    saving
                                }
                                placeholder="Descripción opcional del producto..."
                                rows={4}
                                onChange={(
                                    event,
                                ) =>
                                    setDescription(
                                        event.target
                                            .value,
                                    )
                                }
                            />
                        </div>


                        {/*
                        |--------------------------------------------------------------------------
                        | Imagen
                        |--------------------------------------------------------------------------
                        */}

                        <div className="space-y-2">
                            <Label>
                                Imagen del producto
                            </Label>

                            <div className="overflow-hidden rounded-xl border bg-background">

                                {/*
                                 * Preview
                                 */}
                                <div className="relative aspect-[16/9] overflow-hidden bg-muted">
                                    {imagePreview ? (
                                        <img
                                            src={
                                                imagePreview
                                            }
                                            alt="Vista previa del producto"
                                            className="size-full object-cover"
                                        />
                                    ) : (
                                        <div className="flex size-full flex-col items-center justify-center gap-2 text-muted-foreground">
                                            <div className="flex size-12 items-center justify-center rounded-full bg-background">
                                                <ImageIcon className="size-5" />
                                            </div>

                                            <p className="text-sm">
                                                Sin imagen
                                            </p>
                                        </div>
                                    )}

                                    {/*
                                     * Solo mostramos X cuando
                                     * seleccionamos una imagen
                                     * nueva.
                                     *
                                     * Todavía no implementamos
                                     * eliminación de una imagen
                                     * ya guardada.
                                     */}
                                    {image && (
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            size="icon"
                                            className="absolute right-2 top-2 size-8 shadow-sm"
                                            disabled={
                                                saving
                                            }
                                            onClick={
                                                handleClearNewImage
                                            }
                                        >
                                            <XIcon className="size-4" />

                                            <span className="sr-only">
                                                Descartar imagen
                                            </span>
                                        </Button>
                                    )}
                                </div>

                                {/*
                                 * Selector de archivo
                                 */}
                                <div className="p-3">
                                    <label
                                        htmlFor="product-image"
                                        className="
                                            flex
                                            cursor-pointer
                                            items-center
                                            justify-center
                                            gap-2
                                            rounded-md
                                            border
                                            border-dashed
                                            px-4
                                            py-3
                                            text-sm
                                            font-medium
                                            text-muted-foreground
                                            transition-colors
                                            hover:bg-muted/50
                                            hover:text-foreground
                                        "
                                    >
                                        <UploadIcon className="size-4" />

                                        {imagePreview
                                            ? 'Cambiar imagen'
                                            : 'Subir imagen'}
                                    </label>

                                    <Input
                                        id="product-image"
                                        type="file"
                                        accept="
                                            image/jpeg,
                                            image/png,
                                            image/webp
                                        "
                                        className="hidden"
                                        disabled={
                                            saving
                                        }
                                        onChange={
                                            handleImageChange
                                        }
                                    />

                                    <p className="mt-2 text-center text-xs text-muted-foreground">
                                        JPG, PNG o WebP · Máximo 5 MB
                                    </p>
                                </div>
                            </div>
                        </div>

                        {/*
                        |--------------------------------------------------------------------------
                        | Orden
                        |--------------------------------------------------------------------------
                        */}

                        {isEditing && (
                            <div className="space-y-2">
                                <Label htmlFor="product-order">
                                    Orden
                                </Label>

                                <Input
                                    id="product-order"
                                    type="number"
                                    min={0}
                                    step={1}
                                    value={
                                        sortOrder
                                    }
                                    disabled={
                                        saving
                                    }
                                    onChange={(
                                        event,
                                    ) =>
                                        setSortOrder(
                                            event.target
                                                .value,
                                        )
                                    }
                                />

                                <p className="text-xs text-muted-foreground">
                                    Define la posición del producto dentro del menú.
                                </p>
                            </div>
                        )}

                        {/*
                        |--------------------------------------------------------------------------
                        | Estado
                        |--------------------------------------------------------------------------
                        */}

                        <div className="flex items-center justify-between rounded-lg border p-4">
                            <div className="space-y-1">
                                <Label htmlFor="product-active">
                                    Producto activo
                                </Label>

                                <p className="max-w-xs text-xs text-muted-foreground">
                                    Si lo desactivas, el producto seguirá
                                    guardado pero no aparecerá en el punto
                                    de venta.
                                </p>
                            </div>

                            <Switch
                                id="product-active"
                                checked={
                                    isActive
                                }
                                disabled={
                                    saving
                                }
                                onCheckedChange={
                                    setIsActive
                                }
                            />
                        </div>

                        {/*
                        |--------------------------------------------------------------------------
                        | Error
                        |--------------------------------------------------------------------------
                        */}

                        {error && (
                            <div className="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">
                                {error}
                            </div>
                        )}
                        {product && <ProductInventory productId={product.id} />}
                    </div>

                    <SheetFooter className="border-t px-4 py-4">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={
                                saving
                            }
                            onClick={() =>
                                onOpenChange(
                                    false,
                                )
                            }
                        >
                            Cancelar
                        </Button>

                        <Button
                            type="submit"
                            disabled={
                                saving ||
                                !name.trim() ||
                                !categoryId ||
                                price === ''
                            }
                        >
                            {saving
                                ? 'Guardando...'
                                : isEditing
                                    ? 'Guardar cambios'
                                    : 'Crear producto'}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    )
}
