export type ModifierOption = {
  id: string
  name: string
  price: string
  effect: 'none' | 'add' | 'remove' | 'replace' | 'swap'
  target_type: 'supply' | 'recipe'
  target_id: string
  quantity: string
  source_type?: 'supply' | 'recipe'
  source_id?: string
  source_quantity?: string
}
export type ModifierGroup = { id: string; name: string; kind: 'size' | 'extras'; version: number; is_active: boolean; options: { id: string; name: string }[] }
export type ProductModifierGroup = { id: string; name: string; kind: 'size' | 'extras'; min: number; max: number; options: ModifierOption[] }
export type ModifierSnapshot = { id: string; group_name: string; option_name: string; price_adjustment: string }
