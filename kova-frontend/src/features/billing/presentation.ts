export function money(amount: number, currency = 'MXN') {
  return new Intl.NumberFormat('es-MX', { style: 'currency', currency }).format(amount / 100)
}
export function date(value?: string | null) {
  return value ? new Date(value).toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' }) : 'Por confirmar'
}
export const labels: Record<string, string> = {
  max_users: 'Usuarios', max_products: 'Productos', monthly_sales_limit: 'Ventas mensuales',
  included_branches: 'Sucursales', included_stamps: 'Timbres mensuales',
  allows_table_orders: 'Órdenes por mesa', allows_cash_shifts: 'Turnos de caja',
  allows_inventory: 'Inventario', allows_invoicing: 'Facturación electrónica',
}
export function benefit(code: string, value: number | boolean | null) {
  if (value === null) return 'Sin límite'
  if (typeof value === 'boolean') return value ? 'Incluido' : 'No incluido'
  return code === 'monthly_sales_limit' ? money(value) : value.toLocaleString('es-MX')
}
