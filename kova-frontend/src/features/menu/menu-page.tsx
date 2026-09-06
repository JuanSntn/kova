// src/features/menu/menu-page.tsx

import { useState } from 'react'

import { CategoriesTab } from '@/features/products/categories-tab'
import { ProductsPage } from '@/features/products/products-page'

type MenuView =
    | 'products'
    | 'categories'

export function MenuPage() {
    const [view, setView] =
        useState<MenuView>('products')

    return (
        <>
            <nav
                aria-label="Secciones del menú"
                className="-mb-px flex gap-5 border-b"
            >
                <MenuNavButton
                    active={view === 'products'}
                    onClick={() =>
                        setView('products')
                    }
                >
                    Productos
                </MenuNavButton>

                <MenuNavButton
                    active={view === 'categories'}
                    onClick={() =>
                        setView('categories')
                    }
                >
                    Categorías
                </MenuNavButton>
            </nav>

            <div className="space-y-3">
                {view === 'products' ? (
                    <ProductsPage />
                ) : (
                    <CategoriesTab />
                )}
            </div>
        </>
    )
}

function MenuNavButton({
    active,
    children,
    onClick,
}: {
    active: boolean
    children: React.ReactNode
    onClick: () => void
}) {
    return (
        <button
            type="button"
            aria-current={
                active
                    ? 'page'
                    : undefined
            }
            className={[
                'border-b-2 px-0 py-2.5 text-xs font-medium transition-colors',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
                active
                    ? 'border-primary text-foreground'
                    : 'border-transparent text-muted-foreground hover:text-foreground',
            ].join(' ')}
            onClick={onClick}
        >
            {children}
        </button>
    )
}