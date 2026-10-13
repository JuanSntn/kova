import { test, expect, type Page } from '@playwright/test'

async function setup(page: Page) {
  const supplies: Record<string, unknown>[] = []
  await page.route('**/api/**', async route => {
    const path = new URL(route.request().url()).pathname
    const json = (body: unknown) => route.fulfill({ json: body })
    if (path === '/api/auth/me') return json({ user: { name: 'Ana', email: 'ana@example.test', email_verified_at: '2026-09-28', account_type: 'email' }, tenants: [{ id: '11111111-1111-4111-8111-111111111111', name: 'Prueba', slug: 'prueba', abilities: ['inventory.view', 'inventory.manage'] }] })
    if (path === '/api/navigation') return json({ sections: [{ key: 'catalog', label: 'Catálogo', items: [{ key: 'inventory', path: '/inventory', label: 'Inventario', icon: 'package' }] }] })
    if (path === '/api/context/branches') return json({ data: [] })
    if (path === '/api/inventory/catalog') return json({ categories: [{ id: 'category-1', name: 'Cocina' }], supplies, recipes: [], can_manage: true })
    if (path === '/api/inventory/supplies') {
      if (route.request().method() === 'POST') {
        const data = { ...route.request().postDataJSON(), id: 'supply-1', category: 'Cocina', version: 1 }
        supplies.push(data)
        return json({ data })
      }
      return json({ data: supplies, taxes: [], meta: { page: 1, last_page: 1, total: supplies.length, can_manage: true } })
    }
    return json({ data: [] })
  })
  await page.goto('/prueba/inventory')
}
for (const width of [390, 1440]) test('inventory manual form and conversion '+width, async ({ page }) => {
  await page.setViewportSize({ width, height: 1000 })
  await setup(page)
  await page.getByRole('button', { name: 'Agregar insumo', exact: true }).first().click()
  await page.getByLabel('Nombre', { exact: true }).fill('Aceite vegetal')
  await page.getByLabel('Categoría', { exact: true }).click()
  await page.getByRole('option', { name: 'Cocina', exact: true }).click()
  await page.getByLabel('Unidad base', { exact: true }).click()
  await page.getByRole('option', { name: 'Mililitro (ml)', exact: true }).click()
  await page.getByRole('button', { name: 'Agregar presentación' }).click()
  await page.getByLabel('Nombre', { exact: true }).last().fill('Garrafa')
  await page.getByLabel('Contenido por presentación').fill('5')
  await page.getByLabel('Unidad del contenido').click()
  await page.getByRole('option', { name: 'Litro (L)', exact: true }).click()
  await expect(page.getByText('5,000 ml', { exact: true })).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy()
  await page.screenshot({ path: 'test-results/inventory-form-'+width+'.png', fullPage: true })
  await page.getByRole('button', { name: 'Guardar insumo' }).click()
  await expect(page.getByRole('cell', { name: 'Aceite vegetal Cocina', exact: true })).toBeVisible()
  await page.reload()
  await expect(page.getByRole('cell', { name: 'Aceite vegetal Cocina', exact: true })).toBeVisible()
})

for (const [type, heading, field] of [
  ['Receta', 'Nueva receta', null],
  ['Receta de producción', 'Nueva receta de producción', 'Insumo producido'],
  ['Subreceta', 'Nueva subreceta', 'Cantidad de referencia'],
] as const) test('separate recipe workflow: ' + type, async ({ page }) => {
  await setup(page)
  await page.getByRole('button', { name: 'Recetas', exact: true }).click()
  await page.getByRole('button', { name: 'Agregar receta', exact: true }).click()
  await page.getByRole('menuitem', { name: type, exact: true }).click()
  await expect(page.getByRole('heading', { name: heading, exact: true })).toBeVisible()
  if (field) await expect(page.getByLabel(field, { exact: true })).toBeVisible()
  else await expect(page.getByLabel('Cantidad de referencia')).toHaveCount(0)
  await page.getByRole('button', { name: 'Asociar subreceta', exact: true }).click()
  await expect(page.getByRole('combobox', { name: 'Componente', exact: true })).toBeVisible()
  const panel = page.getByRole('dialog')
  await expect(panel).toHaveCSS('width', '500px')
  const component = await page.getByRole('combobox', { name: 'Componente', exact: true }).boundingBox()
  const kind = await page.getByRole('combobox', { name: 'Tipo', exact: true }).boundingBox()
  expect(Math.abs(component!.y - kind!.y)).toBeLessThan(2)
  expect(component!.width).toBeGreaterThan(kind!.width)
  await page.screenshot({ path: 'test-results/recipe-wide-' + type + '.png' })
  await expect(page.getByRole('button', { name: 'Guardar receta' })).toBeDisabled()
})
