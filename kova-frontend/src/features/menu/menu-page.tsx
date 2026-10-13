// src/features/menu/menu-page.tsx

import { useState } from 'react'
import { SectionNav, SectionNavButton } from '@/components/layout/section-nav'

import { CategoriesTab } from '@/features/products/categories-tab'
import { ProductsPage } from '@/features/products/products-page'
import { ModifiersPage } from '@/features/modifiers/modifiers-page'

type MenuView =
    | 'products'
    | 'categories'
    | 'modifiers'

export function MenuPage() {
    const [view, setView] =
        useState<MenuView>('products')

    return (
        <>
            <SectionNav label="Secciones del menú">
                <SectionNavButton
                    active={view === 'products'}
                    onClick={() =>
                        setView('products')
                    }
                >
                    Productos
                </SectionNavButton>

                <SectionNavButton
                    active={view === 'categories'}
                    onClick={() =>
                        setView('categories')
                    }
                >
                    Categorías
            </SectionNavButton>
                <SectionNavButton active={view === 'modifiers'} onClick={() => setView('modifiers')}>Modificadores</SectionNavButton>
            </SectionNav>

            <div className="space-y-3">
                {view === 'products' ? (
                    <ProductsPage />
                ) : view === 'modifiers' ? <ModifiersPage /> : (
                    <CategoriesTab />
                )}
            </div>
        </>
    )
}
