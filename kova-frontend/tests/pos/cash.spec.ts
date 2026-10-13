import { test, expect, type Page } from '@playwright/test'
const tenant = '11111111-1111-4111-8111-111111111111'
const branch = '22222222-2222-4222-8222-222222222222'
const register = '33333333-3333-4333-8333-333333333333'
const membership = '44444444-4444-4444-8444-444444444444'
const shiftId = '55555555-5555-4555-8555-555555555555'
const countId = '66666666-6666-4666-8666-666666666666'
async function fixture(page: Page, lost = false, firstSetup = false, lostClose = false) {
  let hasRegister = !firstSetup
  let shift: Record<string, unknown> | null = null
  const attempts: { path: string; key: string | undefined; body: unknown }[] = []
  const results = new Map<string, unknown>()
  const movements: unknown[] = []
  let activity = 0
  await page.route('**/api/**', async route => {
    const request = route.request(); const path = new URL(request.url()).pathname
    const json = (body: unknown, status = 200) => route.fulfill({ status, json: body })
    if (path === '/api/auth/me') return json({ user: { name: 'Ana', email: null, account_type: 'tenant_local' }, tenants: [{ id: tenant, slug: 'prueba', name: 'Prueba', membership_id: membership, abilities: ['cash.view','cash.operate','orders.create'] }] })
    if (path === '/api/navigation') return json({ sections: [{ key: 'sales', label: 'Punto de venta', items: [{ key: 'cash', label: 'Caja y turnos', path: '/cash', icon: 'wallet' }] }] })
    if (path === '/api/context/branches') return json({ data: [{ id: branch, name: 'Principal', code: 'PRIN' }] })
    if (path === '/api/cash/context') return json({ data: { feature_enabled: true, shift_required: true, can_checkout: shift?.status === 'OPEN', closure_required: false, current_shift: shift?.status === 'OPEN' ? shift : null,
      permissions: { view: true, operate: true, manage: firstSetup }, available_actions: { open: shift?.status !== 'OPEN', move: shift?.status === 'OPEN', close: shift?.status === 'OPEN', manage_registers: firstSetup } } })
    if (path === '/api/cash/registers' && request.method() === 'POST') { hasRegister = true; return json({ data: { id: register, name: 'Principal', is_active: true, busy: false } }, 201) }
    if (path === '/api/cash/registers') return json({ data: hasRegister ? [{ id: register, name: 'Principal', is_active: true, busy: shift?.status === 'OPEN' }] : [], meta: { page: 1, last_page: 1, total: hasRegister ? 1 : 0 } })
    if (request.method() === 'GET' && path === '/api/cash/shifts') return json({ data: shift ? [shift] : [], meta: { page: 1, last_page: 1, total: shift ? 1 : 0 } })
    if (request.method() === 'GET' && path === `/api/cash/shifts/${shiftId}`) return json({ data: shift })
    if (request.method() === 'POST' && path.startsWith('/api/cash/shifts')) {
      const key = request.headers()['idempotency-key']; const body = request.postDataJSON()
      expect(request.headers()['x-tenant-id']).toBe(tenant); expect(request.headers()['x-branch-id']).toBe(branch)
      attempts.push({ path, key, body })
      if (results.has(key!)) return json({ data: results.get(key!) })
      let result: unknown
      if (path === '/api/cash/shifts') {
        shift = { id: shiftId, register: { id: register, name: 'Principal' }, branch: { id: branch, name: 'Principal' }, responsible: 'Ana', is_own: true, status: 'OPEN', business_date: '2026-09-26', opening_cash: body.opening_cash, opened_timezone: 'America/Mexico_City', opened_at: '2026-09-26 09:00', closed_at: null, sales_count: 0, movements }
        result = shift
      } else if (path.endsWith('/movements')) {
        result = { id: key, ...body, author: 'Ana', created_at: '2026-09-26 09:30' }; movements.push(result); activity++
      } else if (path.endsWith('/counts')) {
        const counted = body.counted_cash ?? (Object.entries(body.denominations).reduce((sum, [d, n]) => sum + Number(d) * Number(n), 0) / 100).toFixed(2)
        result = { id: countId, shift_id: shiftId, activity_version: activity, expected_cash: '1000.00', counted_cash: counted, difference: (Number(counted) - 1000).toFixed(2) }
      } else {
        shift = { ...shift, status: 'CLOSED', expected_cash: '1000.00', counted_cash: '990.00', difference: '-10.00', difference_reason: body.difference_reason, closed_by: 'Ana', closed_at: '2026-09-26 18:00' }; result = shift
      }
      results.set(key!, result)
      if (lostClose && path.endsWith('/close')) { lostClose = false; return route.abort('failed') }
      if (lost && path === '/api/cash/shifts') { lost = false; return route.abort('failed') }
      return json({ data: result }, 201)
    }
    return json({ message: 'Unexpected: '+path }, 404)
  })
  await page.goto('/prueba/cash')
  await expect(page.getByRole('heading', { name: firstSetup ? 'Crea tu primera caja' : 'Sin turno abierto' })).toBeVisible()
  return attempts
}
async function open(page: Page) {
  await page.getByRole('button', { name: 'Abrir turno', exact: true }).click()
  await page.getByLabel('Caja', { exact: true }).click()
  await page.getByRole('option', { name: 'Principal' }).click()
  await page.getByLabel('Fondo inicial (MXN)').fill('1000.00')
  await page.getByRole('button', { name: 'Abrir turno', exact: true }).click()
}
test('cash opening response loss survives reload and reuses exact key', async ({ page }) => {
  const attempts = await fixture(page, true); await open(page)
  await expect(page.getByRole('button', { name: 'Verificar operación' })).toBeVisible()
  await page.reload()
  await page.getByRole('button', { name: 'Verificar operación' }).click()
  await expect(page.getByText('Turno abierto.', { exact: true })).toBeVisible()
  expect(attempts).toHaveLength(2); expect(attempts[0]).toEqual(attempts[1])
})
test('blind count reveals result only after count and closes with reason', async ({ page }) => {
  await fixture(page); await open(page)
  await expect(page.getByText('Efectivo esperado', { exact: true })).not.toBeVisible()
  await page.getByRole('button', { name: 'Cerrar turno', exact: true }).click()
  await page.getByLabel('Efectivo contado (MXN)').fill('990.00')
  await page.getByRole('button', { name: 'Revisar conteo' }).click()
  await expect(page.getByText('Faltante', { exact: true })).toBeVisible()
  await page.reload()
  await expect(page.getByText('Faltante', { exact: true })).toBeVisible()
  await page.getByLabel('Explicación de la diferencia').fill('Error al entregar cambio')
  await page.getByRole('button', { name: 'Confirmar cierre' }).click()
  await expect(page.getByRole('heading', { name: 'Turno cerrado', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Confirmar cierre' })).not.toBeVisible()
})
test('cash storage failure prevents submitting opening', async ({ page }) => {
  const attempts = await fixture(page)
  await page.evaluate(() => { Storage.prototype.setItem = () => { throw new Error('quota') } })
  await open(page)
  await expect(page.getByText(/No se puede guardar el intento/)).toBeVisible()
  expect(attempts).toHaveLength(0)
})
for (const width of [390,768,1440]) test('cash keyboard and reflow '+width, async ({ page }) => {
  await page.setViewportSize({ width, height: 950 }); await fixture(page); await open(page)
  const close = page.getByRole('button', { name: 'Cerrar turno', exact: true })
  await close.focus(); await page.keyboard.press('Enter')
  await page.getByLabel('Forma de conteo').click(); await page.getByRole('option', { name: 'Contar billetes y monedas' }).click()
  await expect(page.getByLabel('$1,000.00', { exact: true })).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy()
  await page.screenshot({ path: `test-results/cash-${width}.png`, animations: 'disabled' })
})

test('first setup guides owner to create a register before offering a shift', async ({ page }) => {
  await fixture(page, false, true)
  await expect(page.getByLabel('Caja', { exact: true })).not.toBeVisible()
  await page.getByRole('button', { name: 'Crear caja', exact: true }).click()
  await expect(page.getByPlaceholder('Ej. Caja principal')).toBeVisible()
  await page.screenshot({ path: 'test-results/cash-first-setup.png', animations: 'disabled' })
  await page.getByLabel('Nombre de la nueva caja').fill('Caja principal')
  await page.getByRole('button', { name: 'Crear caja', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Sin turno abierto' })).toBeVisible()
  await open(page)
  await expect(page.getByText('Turno abierto.', { exact: true })).toBeVisible()
})
test('movement fields are shown on demand with contextual examples', async ({ page }) => {
  await fixture(page); await open(page)
  await expect(page.getByLabel('Importe (MXN)', { exact: true })).not.toBeVisible()
  await page.getByRole('button', { name: 'Salida', exact: true }).click()
  await expect(page.getByPlaceholder('Ej. Compra de insumos')).toBeVisible()
  await page.getByRole('button', { name: 'Cancelar', exact: true }).click()
  await expect(page.getByLabel('Importe (MXN)', { exact: true })).not.toBeVisible()
  await expect(page.getByRole('button', { name: 'Cerrar turno', exact: true })).toBeVisible()
})

test('review remains mounted while close is awaiting response', async ({ page }) => {
  await fixture(page); await open(page)
  await page.getByRole('button', { name: 'Cerrar turno', exact: true }).click()
  await page.getByLabel('Efectivo contado (MXN)').fill('990.00')
  await page.getByRole('button', { name: 'Revisar conteo' }).click()
  await page.getByLabel('Explicación de la diferencia').fill('Error de cambio')
  let release!: () => void
  const pending = new Promise<void>(resolve => { release = resolve })
  await page.route('**/api/cash/shifts/*/close', async route => { await pending; await route.fallback() })
  await page.getByRole('button', { name: 'Confirmar cierre' }).click()
  try {
    await expect(page.getByRole('button', { name: 'Cerrando turno…' })).toBeDisabled()
    await expect(page.getByRole('heading', { name: 'Revisa y confirma el cierre' })).toBeVisible()
    await expect(page.getByLabel('Explicación de la diferencia')).toHaveValue('Error de cambio')
    await expect(page.getByRole('button', { name: 'Cerrar turno', exact: true })).not.toBeVisible()
  } finally { release() }
  await expect(page.getByRole('heading', { name: 'Turno cerrado', exact: true })).toBeVisible()
})

async function reviewCount(page: Page, total = '1000.00') {
  await page.getByRole('button', { name: 'Cerrar turno', exact: true }).click()
  await page.getByLabel('Efectivo contado (MXN)').fill(total)
  await page.getByRole('button', { name: 'Revisar conteo' }).click()
  await expect(page.getByRole('heading', { name: 'Revisa y confirma el cierre' })).toBeVisible()
  await expect(page.getByLabel('Efectivo contado (MXN)')).not.toBeVisible()
}
test('close response loss restores review and retries the same operation after reload', async ({ page }) => {
  const attempts = await fixture(page, false, false, true); await open(page); await reviewCount(page, '990.00')
  await page.getByLabel('Explicación de la diferencia').fill('Cambio entregado de más')
  await page.getByRole('button', { name: 'Confirmar cierre' }).click()
  await expect(page.getByRole('button', { name: 'Verificar cierre' })).toBeVisible()
  await page.reload()
  await expect(page.getByLabel('Explicación de la diferencia')).toHaveValue('Cambio entregado de más')
  await page.getByRole('button', { name: 'Verificar cierre' }).click()
  await expect(page.getByRole('heading', { name: 'Turno cerrado', exact: true })).toBeVisible()
  const closes = attempts.filter(a => a.path.endsWith('/close'))
  expect(closes).toHaveLength(2); expect(closes[0]).toEqual(closes[1])
  await page.getByRole('button', { name: 'Listo' }).click()
  await expect(page.getByRole('dialog')).not.toBeVisible()
  await expect(page.getByRole('heading', { name: 'Sin turno abierto' })).toBeVisible()
})
test('stale count returns to an empty count step and requires recount', async ({ page }) => {
  await fixture(page); await open(page); await reviewCount(page)
  await page.route('**/api/cash/shifts/*/close', route => route.fulfill({ status: 409, json: { code: 'CASH_COUNT_STALE', message: 'Hubo actividad después del conteo. Cuenta nuevamente antes de cerrar.' } }))
  await page.getByRole('button', { name: 'Confirmar cierre' }).click()
  await expect(page.getByRole('heading', { name: 'Contar efectivo' })).toBeVisible()
  await expect(page.getByLabel('Efectivo contado (MXN)')).toHaveValue('')
  await expect(page.getByRole('button', { name: 'Confirmar cierre' })).not.toBeVisible()
})
test('count draft survives reload and can be corrected without stacking forms', async ({ page }) => {
  await fixture(page); await open(page)
  await page.getByRole('button', { name: 'Cerrar turno', exact: true }).click()
  await page.getByLabel('Efectivo contado (MXN)').fill('1010.00')
  await page.reload()
  await expect(page.getByLabel('Efectivo contado (MXN)')).toHaveValue('1010.00')
  await page.getByRole('button', { name: 'Revisar conteo' }).click()
  await expect(page.getByText('Sobrante', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Volver al conteo' }).click()
  await expect(page.getByLabel('Efectivo contado (MXN)')).toHaveValue('1010.00')
  await expect(page.getByRole('button', { name: 'Confirmar cierre' })).not.toBeVisible()
  await page.getByLabel('Efectivo contado (MXN)').fill('1000.00')
  await page.getByRole('button', { name: 'Revisar conteo' }).click()
  await expect(page.getByText('Sin diferencia', { exact: true })).toBeVisible()
  await expect(page.getByLabel('Explicación de la diferencia')).not.toBeVisible()
})

test('denomination count and cancel keep the flow in one focused window', async ({ page }) => {
  await fixture(page); await open(page)
  await page.getByRole('button', { name: 'Cerrar turno', exact: true }).click()
  await page.getByLabel('Forma de conteo').click()
  await page.getByRole('option', { name: 'Contar billetes y monedas' }).click()
  await page.getByLabel('$500.00', { exact: true }).fill('2')
  await page.getByRole('button', { name: 'Revisar conteo' }).click()
  await expect(page.getByText('Sin diferencia', { exact: true })).toBeVisible()
  await expect(page.getByLabel('Forma de conteo')).not.toBeVisible()
  await page.screenshot({ path: 'test-results/cash-review.png' })
  await page.getByRole('button', { name: 'Cancelar', exact: true }).click()
  await expect(page.getByRole('dialog')).not.toBeVisible()
  await page.reload()
  await expect(page.getByRole('button', { name: 'Cerrar turno', exact: true })).toBeVisible()
  await expect(page.getByRole('dialog')).not.toBeVisible()
  await page.screenshot({ path: 'test-results/cash-current.png' })
  await page.getByRole('tab', { name: 'Historial', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Historial y supervisión' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Cerrar turno', exact: true })).not.toBeVisible()
})
