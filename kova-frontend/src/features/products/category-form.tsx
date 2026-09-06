import {
    useEffect,
    useState,
} from 'react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet'
import { Switch } from '@/components/ui/switch'

import {
    createCategory,
    getApiErrorMessage,
    updateCategory,
} from './api'

import type {
    Category,
    CreateCategoryPayload,
} from './types'

type CategoryFormProps = {
    open: boolean

    onOpenChange: (
        open: boolean
    ) => void

    category?: Category | null

    onSaved: (
        category: Category
    ) => void
}

export function CategoryForm({
    open,
    onOpenChange,
    category,
    onSaved,
}: CategoryFormProps) {
    const isEditing =
        Boolean(category)

    const [name, setName] =
        useState('')

    const [isActive, setIsActive] =
        useState(true)

    const [saving, setSaving] =
        useState(false)

    const [error, setError] =
        useState<string | null>(null)

    const [sortOrder, setSortOrder] =
        useState('0')

    useEffect(() => {
        if (!open) {
            return
        }

        setName(
            category?.name ?? ''
        )

        setIsActive(
            category?.is_active ?? true
        )

        setSortOrder(
            String(
                category?.sort_order ?? 0
            )
        )

        setError(null)
    }, [
        open,
        category,
    ])

    async function handleSubmit(
        event: React.FormEvent<HTMLFormElement>
    ) {
        event.preventDefault()

        const normalizedName =
            name.trim()

        if (!normalizedName) {
            setError(
                'El nombre de la categoría es obligatorio.'
            )

            return
        }

        const payload: CreateCategoryPayload = {
            name: normalizedName,
            is_active: isActive,
        }

        if (isEditing) {
            const parsedSortOrder =
                Number(sortOrder)

            if (
                !Number.isInteger(parsedSortOrder) ||
                parsedSortOrder < 0
            ) {
                setError(
                    'El orden debe ser un número entero igual o mayor a 0.'
                )

                return
            }

            payload.sort_order =
                parsedSortOrder
        }

        setSaving(true)
        setError(null)

        try {
            const savedCategory =
                isEditing && category
                    ? await updateCategory(
                        category.id,
                        payload,
                    )
                    : await createCategory(
                        payload,
                    )

            onSaved(savedCategory)

            onOpenChange(false)
        } catch (error) {
            setError(
                getApiErrorMessage(error)
            )
        } finally {
            setSaving(false)
        }
    }

    return (
        <Sheet
            open={open}
            onOpenChange={onOpenChange}
        >
            <SheetContent
                side="right"
                className="w-full sm:max-w-md"
            >
                <form
                    onSubmit={handleSubmit}
                    className="flex h-full flex-col"
                >
                    <SheetHeader>
                        <SheetTitle>
                            {isEditing
                                ? 'Editar categoría'
                                : 'Nueva categoría'}
                        </SheetTitle>

                        <SheetDescription>
                            {isEditing
                                ? 'Modifica los datos de la categoría.'
                                : 'Crea una categoría para organizar los productos de tu menú.'}
                        </SheetDescription>
                    </SheetHeader>

                    <div className="flex-1 space-y-6 overflow-y-auto px-4 py-6">
                        <div className="space-y-2">
                            <Label htmlFor="category-name">
                                Nombre de la categoría
                            </Label>

                            <Input
                                id="category-name"
                                value={name}
                                onChange={(event) =>
                                    setName(
                                        event.target.value
                                    )
                                }
                                placeholder="Ej. Bebidas"
                                maxLength={120}
                                autoFocus
                                disabled={saving}
                            />

                            <p className="text-xs text-muted-foreground">
                                Ejemplos: Bebidas,
                                Entradas, Hamburguesas
                                o Postres.
                            </p>
                        </div>

                        {isEditing && (
                            <div className="space-y-2">
                                <Label htmlFor="category-order">
                                    Orden
                                </Label>

                                <Input
                                    id="category-order"
                                    type="number"
                                    min={0}
                                    step={1}
                                    value={sortOrder}
                                    onChange={(event) =>
                                        setSortOrder(
                                            event.target.value
                                        )
                                    }
                                    disabled={saving}
                                />

                                <p className="text-xs text-muted-foreground">
                                    Define la posición en la que
                                    aparecerá esta categoría dentro
                                    del menú.
                                </p>
                            </div>
                        )}

                        <div className="flex items-center justify-between rounded-lg border p-4">
                            <div className="space-y-1">
                                <Label htmlFor="category-active">
                                    Categoría activa
                                </Label>

                                <p className="max-w-[260px] text-xs text-muted-foreground">
                                    Si está desactivada,
                                    la categoría se guarda
                                    pero no debería mostrarse
                                    en el punto de venta.
                                </p>
                            </div>

                            <Switch
                                id="category-active"
                                checked={isActive}
                                onCheckedChange={
                                    setIsActive
                                }
                                disabled={saving}
                            />
                        </div>

                        {error && (
                            <div className="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">
                                {error}
                            </div>
                        )}
                    </div>

                    <SheetFooter className="border-t px-4 py-4">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={saving}
                            onClick={() =>
                                onOpenChange(false)
                            }
                        >
                            Cancelar
                        </Button>

                        <Button
                            type="submit"
                            disabled={
                                saving ||
                                name.trim() === ''
                            }
                        >
                            {saving
                                ? 'Guardando...'
                                : isEditing
                                    ? 'Guardar cambios'
                                    : 'Crear categoría'}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    )
}