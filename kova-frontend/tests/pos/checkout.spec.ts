import { test, expect, type Page } from '@playwright/test'

const tenant = '11111111-1111-4111-8111-111111111111'
const branch = '22222222-2222-4222-8222-222222222222'
const product = '33333333-3333-4333-8333-333333333333'
const membership = '44444444-4444-4444-8444-444444444444'
const category = { id: 'food', name: 'Comida', sort_order: 0, is_active: true, billing_enabled: true, can_use: true, locked_by_plan: false }
const order = { id: 'order-public', folio: 'KVA-000001', status: 'paid', order_type: 'counter', payment_method: 'card', subtotal: '10.00', total: '10.00', tax_base_total: '8.62', tax_total: '1.38',
  items: [{ product_id: product, product_name: 'Café', unit_price: '10.00', quantity: 1, line_total: '10.00' }] }

async function fixture(page: Page, options: { lost?: boolean; committed?: boolean; changed?: boolean; needsShift?: boolean; activeShift?: boolean } = {}) {
  let committed = false
  const attempts: { key: string | undefined; body: unknown }[] = []
  await page.route('**/api/**', async route => {
    const path = new URL(route.request().url()).pathname
    const json = (body: unknown, status = 200) => route.fulfill({ status, json: body })
    if (path === '/api/auth/me') return json({ user: { name: 'Prueba', email: 'test@example.test', account_type: 'email', email_verified_at: '2026-01-01' },
      tenants: [{ id: tenant, slug: 'prueba', name: 'Prueba', membership_id: membership, abilities: ['orders.create', 'orders.view'] }] })
    if (path === '/api/navigation') return json({ sections: [{ key: 'pos', label: 'Punto de venta', order: 1, items: [{ key: 'pos', label: 'POS', path: '/pos', icon: 'ShoppingCart', order: 1 }] }] })
    if (path === '/api/cash/context') return json({ data: { feature_enabled: !!options.needsShift, shift_required: !!options.needsShift, closure_required: false, can_checkout: !options.needsShift, current_shift: options.activeShift ? { register: { name: 'Pedrito sola' }, responsible: 'Juan Carlos León' } : null, permissions: { view: !!options.needsShift, operate: false, manage: false }, available_actions: {} } })
    if (path === '/api/context/branches') return json({ data: [{ id: branch, name: 'Principal', code: 'PRIN', timezone: 'America/Mexico_City' }, { id: '55555555-5555-4555-8555-555555555555', name: 'Centro', code: 'CEN', timezone: 'America/Mexico_City' }] })
    if (path === '/api/pos/catalog/categories') return json({ data: [category] })
    if (path === '/api/pos/catalog/products') return json({ data: [{ id: product, name: 'Café', price: '10.00', category, is_active: true, billing_enabled: true, can_use: true, sort_order: 0, image_path: null }] })
    if (path === '/api/pos/checkout/quote') return json({ data: { quote_token: 'signed-fixture', expires_at: Date.now() / 1000 + 300, subtotal: '10.00', total: '10.00', tax_base_total: '8.62', tax_total: '1.38', lines: [{ product_id: product, product_name_snapshot: 'Café', unit_price_snapshot: '10.00', quantity: 1, line_total: '10.00' }] } })
    if (path === '/api/pos/orders') {
      expect(route.request().headers()['x-tenant-id']).toBe(tenant)
      expect(route.request().headers()['x-branch-id']).toBe(branch)
      attempts.push({ key: route.request().headers()['idempotency-key'], body: route.request().postDataJSON() })
      if (options.changed) { options.changed = false; return json({ code: 'POS_QUOTE_CHANGED', message: 'Los precios cambiaron. Revisa el nuevo resumen.' }, 409) }
      if (options.lost && attempts.length === 1) { committed = options.committed ?? true; return route.abort('failed') }
      committed = true
      return json({ data: order }, 201)
    }
    if (path.startsWith('/api/pos/checkouts/')) return committed ? json({ data: order }) : json({ code: 'POS_CHECKOUT_NOT_FOUND' }, 404)
    // Printing failure must never create another order.
    if (path.endsWith('/ticket')) return json({ message: 'Impresora no disponible' }, 500)
    return json({ message: 'Unexpected mocked endpoint: ' + path }, 404)
  })
  await page.goto('/prueba/pos')
  if (options.needsShift) await expect(page.getByRole('link', { name: 'Abrir caja' })).toBeVisible()
  else await expect(page.getByRole('button', { name: 'Agregar Café' })).toBeVisible()
  return attempts
}
async function prepare(page: Page) {
  await page.getByRole('button', { name: 'Agregar Café' }).click()
  await page.getByRole('button', { name: 'Tarjeta', exact: true }).click()
  await page.getByRole('button', { name: /^Cobrar / }).click()
  await expect(page.getByText('Impuestos incluidos')).toBeVisible()
}
async function confirm(page: Page) {
  await page.getByRole('button', { name: /Confirmar/ }).click()
}

test('recovers a lost committed response after reload without another POST', async ({ page }) => {
  const attempts = await fixture(page, { lost: true })
  await prepare(page); await confirm(page)
  await expect(page.getByText('Resultado pendiente de verificar')).toBeVisible()
  await page.reload()
  await page.getByRole('button', { name: 'Verificar venta', exact: true }).click()
  await expect(page.getByText('Folio KVA-000001', { exact: true })).toBeVisible()
  expect(attempts).toHaveLength(1)
  await page.reload()
  await expect(page.getByText('Folio KVA-000001', { exact: true })).toBeVisible()
  expect(attempts).toHaveLength(1)
})

test('no result keeps the exact original key and body for retry', async ({ page }) => {
  const attempts = await fixture(page, { lost: true, committed: false })
  await prepare(page); await confirm(page)
  await page.getByRole('button', { name: 'Verificar venta', exact: true }).click()
  await expect(page.getByText('Resultado pendiente de verificar')).toBeVisible()
  await page.getByRole('button', { name: 'Reenviar el mismo intento' }).click()
  await expect(page.getByText('Folio KVA-000001', { exact: true })).toBeVisible()
  expect(attempts).toHaveLength(2)
  expect(attempts[0]).toEqual(attempts[1])
})

test('reload restores draft and price changes require another confirmation', async ({ page }) => {
  const attempts = await fixture(page, { changed: true })
  await page.getByRole('button', { name: 'Agregar Café' }).click()
  await page.reload()
  await page.getByRole('button', { name: 'Tarjeta', exact: true }).click()
  await page.getByRole('button', { name: /^Cobrar / }).click(); await confirm(page)
  await expect(page.getByText('Los precios cambiaron. Revisa el nuevo resumen.')).toBeVisible()
  expect(attempts).toHaveLength(1)
  await page.getByRole('button', { name: /^Cobrar / }).click(); await confirm(page)
  await expect(page.getByText('Folio KVA-000001', { exact: true })).toBeVisible()
  expect(attempts[0].key).not.toEqual(attempts[1].key)
})

test('storage unavailable blocks POST instead of losing recovery', async ({ page }) => {
  const attempts = await fixture(page)
  await page.evaluate(() => { Storage.prototype.setItem = () => { throw new Error('storage full') } })
  await page.getByRole('button', { name: 'Agregar Café' }).click()
  await page.getByRole('button', { name: /^Cobrar / }).click()
  await expect(page.getByText(/No se pudo guardar el estado/)).toBeVisible()
  expect(attempts).toHaveLength(0)
})

for (const width of [390, 768, 1440]) {
  test('checkout is usable with keyboard at width ' + width, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 })
    await fixture(page); await prepare(page)
    await page.keyboard.press('Tab')
    await expect(page.getByRole('dialog')).toBeVisible()
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy()
    await page.screenshot({ path: 'test-results/checkout-' + width + '.png', animations: 'disabled' })
    await page.keyboard.press('Escape')
    await expect(page.getByRole('dialog')).not.toBeVisible()
  })
}

for (const width of [390, 1440]) test('missing shift appears over blurred catalogue '+width, async ({ page }) => {
  await page.setViewportSize({ width, height: 950 })
  await fixture(page, { needsShift: true })
  const action = page.getByRole('link', { name: 'Abrir caja' })
  await expect(action).toHaveAttribute('href', '/prueba/cash')
  await expect(page.getByText('Necesitas abrir un turno antes de cobrar.')).toHaveCount(1)
  await expect(page.locator('[inert]')).toHaveCSS('filter', 'blur(8px)')
  await expect(page.locator('[inert] input')).toHaveAttribute('placeholder', 'Buscar producto...')
  await expect(page.locator('[inert]').getByText('Todos', { exact: false })).toBeVisible()
  await action.focus()
  await expect(action).toBeFocused()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy()
  await page.screenshot({ path: `test-results/pos-shift-${width}.png` })
})

test('active shift keeps checkout visible within desktop viewport', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  await fixture(page, { activeShift: true })
  await expect(page.getByText('Caja: Pedrito sola · Turno de Juan Carlos León')).toBeVisible()
  const button = page.getByRole('button', { name: /^Cobrar/ })
  const box = await button.boundingBox()
  expect(box).not.toBeNull()
  expect(box!.y + box!.height).toBeLessThanOrEqual(900)
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy()
  await page.screenshot({ path: 'test-results/pos-active-shift.png' })
})
