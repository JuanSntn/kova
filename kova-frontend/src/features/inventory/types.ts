export const units = {
  pz: { label: 'Pieza (pz)', dimension: 'count', factor: 1 },
  g: { label: 'Gramo (g)', dimension: 'mass', factor: 1 },
  kg: { label: 'Kilogramo (kg)', dimension: 'mass', factor: 1000 },
  ml: { label: 'Mililitro (ml)', dimension: 'volume', factor: 1 },
  L: { label: 'Litro (L)', dimension: 'volume', factor: 1000 },
} as const
export type Unit = keyof typeof units
export const isWholeUnit = (unit?: Unit) => !!unit && units[unit].dimension === 'count'
export const isWholeQuantity = (value: string) => value !== '' && Number.isInteger(Number(value))
export type Presentation = {
  id: string; name: string; quantity: string; unit: Unit
  supplier: string; supplierCode: string; price: string; tax: string
}
export type Supply = {
  category_id?: string; version?: number; id: string; name: string; category: string; unit: Unit
  minimum: string; ideal: string; produced: boolean; presentations: Presentation[]
}
export function emptySupply(): Supply {
  return { id: crypto.randomUUID(), name: '', category: '', unit: 'pz', minimum: '', ideal: '', produced: false, presentations: [] }
}
export function emptyPresentation(unit: Unit): Presentation {
  return { id: crypto.randomUUID(), name: '', quantity: '', unit, supplier: '', supplierCode: '', price: '', tax: '' }
}
export function equivalent(p: Presentation, base: Unit): string {
  if (!p.quantity || units[p.unit].dimension !== units[base].dimension) return '—'
  return new Intl.NumberFormat('es-MX', { maximumFractionDigits: 6 }).format(Number(p.quantity) * units[p.unit].factor / units[base].factor) + ' ' + base
}
