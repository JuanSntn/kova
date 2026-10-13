import { test, expect } from '@playwright/test'

const tenant = '11111111-1111-4111-8111-111111111111'
const branch = '22222222-2222-4222-8222-222222222222'
const product = '33333333-3333-4333-8333-333333333333'
const group = '55555555-5555-4555-8555-555555555555'
const large = '66666666-6666-4666-8666-666666666666'
const small = '77777777-7777-4777-8777-777777777777'
const choices = [{ id: small, name: 'Mediana', price: '0.00' }, { id: large, name: 'Grande', price: '10.25' }]

for (const width of [390, 1440]) test('modifiers persist distinct cart lines and authoritative quote ' + width, async ({ page }) => {
  await page.setViewportSize({ width, height: 950 })
  let quoted: { product_id: string; quantity: number; modifiers: string[] }[] = []
  await page.route('**/api/**', async route => {
    const path = new URL(route.request().url()).pathname
    const json = (body: unknown) => route.fulfill({ json: body })
    if (path === '/api/auth/me') return json({ user: { name: 'Ana', email: 'ana@example.test', account_type: 'email', email_verified_at: '2026-01-01' }, tenants: [{ id: tenant, slug: 'prueba', name: 'Prueba', membership_id: 'member', abilities: ['orders.create', 'products.view', 'products.update'] }] })
    if (path === '/api/navigation') return json({ sections: [{ key: 'pos', label: 'Punto de venta', items: [{ key: 'pos', label: 'Caja', path: '/pos', icon: 'ShoppingCart' }] }] })
    if (path === '/api/context/branches') return json({ data: [{ id: branch, name: 'Principal', code: 'PRIN', timezone: 'America/Mexico_City' }] })
    if (path === '/api/cash/context') return json({ data: { feature_enabled: false, shift_required: false, can_checkout: true, current_shift: null, permissions: {}, available_actions: {} } })
    const category = { id: 'category', name: 'Bebidas', can_use: true, is_active: true, billing_enabled: true }
    if (path === '/api/pos/catalog/categories') return json({ data: [category] })
    if (path === '/api/pos/catalog/products') return json({ data: [{ id: product, name: 'Bebida', price: '30.00', category, can_use: true, is_active: true, billing_enabled: true, modifier_groups: [{ id: group, name: 'Tamaño', kind: 'size', min: 1, max: 1, options: choices }] }] })
    if (path === '/api/pos/checkout/quote') {
      quoted = route.request().postDataJSON().items
      expect(route.request().headers()['x-tenant-id']).toBe(tenant)
      expect(route.request().headers()['x-branch-id']).toBe(branch)
      return json({ data: { quote_token: 'signed', total: '70.25', subtotal: '70.25', lines: quoted.map(item => ({ product_id: product, product_name_snapshot: 'Bebida', quantity: item.quantity, unit_price_snapshot: item.modifiers[0] === large ? '40.25' : '30.00', modifiers_snapshot: [{ option_name: item.modifiers[0] === large ? 'Grande' : 'Mediana' }] })) } })
    }
    return json({ data: [] })
  })
  await page.goto('/prueba/pos')
  for (const size of ['Grande', 'Mediana']) {
    await page.getByRole('button', { name: 'Agregar Bebida', exact: true }).click()
    await expect(page.getByRole('button', { name: 'Agregar a la venta' })).toBeDisabled()
    await page.getByRole('radio', { name: new RegExp(size) }).check()
    await page.screenshot({ path: `test-results/modifier-options-${width}.png` })
    await page.getByRole('button', { name: 'Agregar a la venta' }).click()
  }
  await page.reload()
  await expect(page.getByText('Bebida · Grande', { exact: true })).toBeVisible()
  await expect(page.getByText('Bebida · Mediana', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Tarjeta', exact: true }).click()
  await page.getByRole('button', { name: /^Cobrar / }).click()
  await expect(page.getByRole('dialog')).toBeVisible()
  expect(quoted.map(i => i.modifiers)).toEqual([[large], [small]])
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy()
  await page.keyboard.press('Escape')
  await expect(page.getByRole('dialog')).not.toBeVisible()
})

test('menu group creation and product assignment', async ({ page }) => {
  let groups: unknown[] = []
  let assignment: unknown
  await page.route('**/api/**', async route => {
    const path = new URL(route.request().url()).pathname
    const json = (body: unknown) => route.fulfill({ json: body })
    if (path === '/api/auth/me') return json({ user: { name: 'Ana', email_verified_at: '2026-01-01', account_type: 'email' }, tenants: [{ id: tenant, slug: 'prueba', name: 'Prueba', abilities: ['products.view', 'products.update'] }] })
    if (path === '/api/navigation') return json({ sections: [{ key: 'catalog', label: 'Catálogo', items: [{ key: 'menu', path: '/menu', label: 'Menú' }] }] })
    if (path === '/api/context/branches') return json({ data: [] })
    if (path === '/api/pos/modifiers') {
      if (route.request().method() === 'POST') { groups = [{ ...route.request().postDataJSON(), id: group, options: [{ id: large, name: 'Grande' }] }]; return json({ data: groups[0] }) }
      return json({ data: groups, can_manage: true })
    }
    if (path === '/api/pos/products') return json({ data: [{ id: product, name: 'Bebida', price: '30.00', category: { id: 'beverages', name: 'Bebidas' }, is_active: true, billing_enabled: true, can_use: true }], meta: { usage: { used: 1, limit: null, is_unlimited: true }, plan: { name: 'Restaurante' } } })
    if (path === `/api/pos/products/${product}/modifiers`) {
      if (route.request().method() === 'PUT') assignment = route.request().postDataJSON()
      return json({ data: [] })
    }
    return json({ data: [] })
  })
  await page.goto('/prueba/menu')
  await page.getByRole('button', { name: 'Modificadores', exact: true }).click()
  await page.getByRole('button', { name: 'Crear grupo' }).click()
  await page.getByLabel('Nombre', { exact: true }).fill('Tamaño')
  await page.getByLabel('Opción 1').fill('Grande')
  await page.getByRole('button', { name: 'Guardar grupo' }).click()
  await expect(page.getByRole('dialog')).not.toBeVisible()
  for (const width of [1440, 390]) {
    await page.setViewportSize({ width, height: 950 })
    await page.screenshot({ path: `test-results/modifiers-list-${width}.png`, fullPage: true })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy()
  }
  await page.getByRole('combobox', { name: 'Producto del menú' }).fill('Bebida')
  await page.getByRole('option', { name: 'Bebida', exact: true }).click()
  await page.getByRole('combobox', { name: 'Agregar grupo' }).click()
  await page.getByRole('option', { name: 'Tamaño', exact: true }).click()
  await page.getByLabel('Importe adicional (MXN)').fill('10.25')
  await page.getByRole('button', { name: 'Guardar configuración' }).click()
  await expect(page.getByRole('dialog')).not.toBeVisible()
  expect(assignment).toMatchObject({ groups: [{ id: group, options: [{ id: large, price: '10.25', effect: 'none' }] }] })
})
