import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useSession } from '@/features/auth/session-context'
import { useBranch } from '@/features/branches/branch-context'
import { SectionNav, SectionNavButton } from '@/components/layout/section-nav'
import { Button } from '@/components/ui/button'
import { InventoryPage } from './inventory-page'
import { CategoriesPanel } from './categories-panel'
import { RecipesPanel } from './recipes-panel'
import { LedgerPanel } from './ledger-panel'
import { AdjustmentPanel } from './adjustment-panel'
import { ExcelPanel } from './excel-panel'
import { catalog } from './workspace-api'
import { errorMessage } from './utils'
import { MenuProductsPanel } from './menu-products-panel'
import { InventoryReportPanel } from './inventory-report-panel'

const sections = { supplies: 'Insumos', categories: 'Categorías', recipes: 'Recetas', products: 'Productos del menú', balances: 'Existencias', adjustments: 'Ajuste', movements: 'Movimientos', production: 'Producción', report: 'Reporte', excel: 'Excel' }
export function InventoryWorkspace() {
  const { activeTenant } = useSession()
  const { activeBranchId } = useBranch()
  const [view, setView] = useState<keyof typeof sections>('supplies')
  const query = useQuery({ queryKey: ['inventory', activeTenant?.id, 'catalog'], queryFn: () => catalog(activeTenant!.id), enabled: !!activeTenant })
  if (!activeTenant) return null
  return <div className="min-w-0 space-y-6"><div className="overflow-x-auto"><SectionNav label="Inventario">{Object.entries(sections).map(([id, label]) => <SectionNavButton key={id} active={view === id} onClick={() => setView(id as typeof view)}>{label}</SectionNavButton>)}</SectionNav></div>
    {query.isPending ? <p role="status">Cargando inventario…</p> : query.isError ? <div role="alert"><p>{errorMessage(query.error)}</p><Button variant="outline" onClick={() => void query.refetch()}>Reintentar</Button></div> : view === 'products' ? <MenuProductsPanel key={activeTenant.id} tenant={activeTenant.id} /> : view === 'supplies' ? <InventoryPage /> : view === 'categories' ? <CategoriesPanel key={activeTenant.id} tenant={activeTenant.id} data={query.data} /> : view === 'recipes' ? <RecipesPanel key={activeTenant.id} tenant={activeTenant.id} data={query.data} /> : view === 'excel' ? <ExcelPanel key={activeTenant.id} tenant={activeTenant.id} manage={query.data.can_manage} /> : view === 'report' ? <InventoryReportPanel key={activeTenant.id + ':' + activeBranchId} tenant={activeTenant.id} branch={activeBranchId ?? ''} /> : view === 'adjustments' ? <AdjustmentPanel key={activeTenant.id + ':' + activeBranchId + ':' + activeTenant.membership_id} tenant={activeTenant.id} branch={activeBranchId ?? ''} membership={activeTenant.membership_id ?? ''} data={query.data} /> : <LedgerPanel key={activeTenant.id + ':' + activeBranchId + ':' + view} tenant={activeTenant.id} branch={activeBranchId ?? ''} membership={activeTenant.membership_id ?? ''} data={query.data} view={view} />}
  </div>
}
