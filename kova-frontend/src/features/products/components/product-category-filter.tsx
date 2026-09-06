import {
    ChevronDownIcon,
    ChevronUpIcon,
} from 'lucide-react'
import { useState } from 'react'

import { Button } from '@/components/ui/button'

import type { Category } from '../types'

type ProductCategoryFilterProps = {
    productsCount: number
    previewCategories: Category[]
    hiddenCategories: Category[]
    categoryCounts: Map<string, number>
    selectedCategory: string
    onSelectCategory: (categoryId: string) => void
}

export function ProductCategoryFilter({
    productsCount,
    previewCategories,
    hiddenCategories,
    categoryCounts,
    selectedCategory,
    onSelectCategory,
}: ProductCategoryFilterProps) {
    const [
        expanded,
        setExpanded,
    ] = useState(false)

    return (
        <div className="space-y-2">
            <div className="flex items-center gap-2 overflow-x-auto pb-1">
                <CategoryButton
                    active={
                        selectedCategory ===
                        'all'
                    }
                    label="Todos"
                    count={productsCount}
                    onClick={() =>
                        onSelectCategory('all')
                    }
                />

                {previewCategories.map(
                    (category) => (
                        <CategoryButton
                            key={category.id}
                            active={
                                selectedCategory ===
                                category.id
                            }
                            label={category.name}
                            count={
                                categoryCounts.get(
                                    category.id,
                                ) ?? 0
                            }
                            onClick={() =>
                                onSelectCategory(
                                    category.id,
                                )
                            }
                        />
                    ),
                )}

                {hiddenCategories.length > 0 && (
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        aria-expanded={expanded}
                        className="h-8 shrink-0 gap-1.5 px-3 text-xs text-muted-foreground"
                        onClick={() =>
                            setExpanded(
                                (current) =>
                                    !current,
                            )
                        }
                    >
                        {expanded ? (
                            <>
                                <ChevronUpIcon className="size-4" />
                                Ocultar
                            </>
                        ) : (
                            <>
                                <ChevronDownIcon className="size-4" />
                                +{hiddenCategories.length}{' '}
                                categorías
                            </>
                        )}
                    </Button>
                )}
            </div>

            <div
                className={[
                    'grid transition-[grid-template-rows,opacity,transform] duration-500',
                    'ease-[cubic-bezier(0.34,1.56,0.64,1)]',
                    expanded
                        ? 'grid-rows-[1fr] translate-y-0 opacity-100'
                        : 'grid-rows-[0fr] -translate-y-1 opacity-0',
                ].join(' ')}
            >
                <div className="overflow-hidden">
                    <div className="max-h-48 overflow-y-auto overscroll-contain rounded-lg border bg-muted/10 p-2.5">
                        <div className="flex flex-wrap gap-2">
                            {hiddenCategories.map(
                                (category) => (
                                    <CategoryButton
                                        key={category.id}
                                        active={
                                            selectedCategory ===
                                            category.id
                                        }
                                        label={category.name}
                                        count={
                                            categoryCounts.get(
                                                category.id,
                                            ) ?? 0
                                        }
                                        onClick={() =>
                                            onSelectCategory(
                                                category.id,
                                            )
                                        }
                                    />
                                ),
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    )
}

function CategoryButton({
    active,
    label,
    count,
    onClick,
}: {
    active: boolean
    label: string
    count: number
    onClick: () => void
}) {
    return (
        <Button
            type="button"
            size="sm"
            variant={
                active
                    ? 'default'
                    : 'outline'
            }
            className="h-8 shrink-0 px-3 text-xs"
            onClick={onClick}
        >
            <span className="max-w-36 truncate">
                {label}
            </span>

            <span className="ml-1 text-[10px] opacity-65">
                {count}
            </span>
        </Button>
    )
}
