import { useState, type ReactNode } from 'react'
import { ChevronDown } from 'lucide-react'
import { Input } from '@/components/ui/input'
import { Field, FieldLabel } from '@/components/ui/field'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
export function Control({ id, label, children }: { id: string; label: string; children: ReactNode }) {
  return <Field><FieldLabel htmlFor={id}>{label}</FieldLabel>{children}</Field>
}
export function Choice({ id, value, options, onChange, placeholder = 'Selecciona una opción' }: { id: string; value: string; options: { id: string; name: string }[]; onChange: (v: string) => void; placeholder?: string }) {
  return <Select value={value} onValueChange={onChange}><SelectTrigger id={id} className="h-10 w-full data-[size=default]:h-10"><SelectValue placeholder={placeholder} /></SelectTrigger><SelectContent>{options.map(o => <SelectItem key={o.id} value={o.id}>{o.name}</SelectItem>)}</SelectContent></Select>
}

export function SearchChoice({ id, value, options, onChange }: Parameters<typeof Choice>[0]) {
  const [search, setSearch] = useState('')
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(0)
  const filtered = options.filter(o => o.name.toLocaleLowerCase().includes(search.trim().toLocaleLowerCase()))
  const selected = options.find(o => o.id === value)
  function choose(option: { id: string; name: string }) { onChange(option.id); setOpen(false); setSearch('') }
  return <div className="relative min-w-0" onBlur={e => { if (!e.currentTarget.contains(e.relatedTarget)) setOpen(false) }}>
    <div className="relative">
      <Input id={id} role="combobox" aria-expanded={open} aria-controls={id + '-options'} aria-autocomplete="list" aria-activedescendant={open && filtered[active] ? id + '-option-' + active : undefined}
        className="h-10 pr-9" placeholder="Busca y selecciona…" value={open ? search : selected?.name ?? ''}
        onFocus={() => { setSearch(''); setActive(0); setOpen(true) }} onClick={() => setOpen(true)}
        onChange={e => { setSearch(e.target.value); setActive(0); setOpen(true) }}
        onKeyDown={e => {
          if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault(); setOpen(true)
            const next = Math.max(0, Math.min(filtered.length - 1, active + (e.key === 'ArrowDown' ? 1 : -1)))
            setActive(next); document.getElementById(id + '-option-' + next)?.scrollIntoView({ block: 'nearest' })
          }
          if (e.key === 'Enter') { e.preventDefault(); if (open && filtered[active]) choose(filtered[active]); else setOpen(true) }
          if (e.key === 'Escape' && open) { e.preventDefault(); e.stopPropagation(); setOpen(false) }
        }} />
      <ChevronDown aria-hidden="true" className="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
    </div>
    {open && <div id={id + '-options'} role="listbox" aria-label="Componentes disponibles" className="absolute z-50 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border bg-popover p-1 text-popover-foreground shadow-md">
      {filtered.length ? filtered.map((option, index) => <button type="button" role="option" id={id + '-option-' + index} key={option.id} tabIndex={-1} aria-selected={option.id === value}
        className={'w-full rounded-md px-3 py-2 text-left text-sm break-words ' + (active === index ? 'bg-accent' : 'hover:bg-accent')}
        onMouseDown={e => e.preventDefault()} onMouseEnter={() => setActive(index)} onClick={() => choose(option)}>{option.name}</button>) : <p className="px-3 py-2 text-sm text-muted-foreground">Sin coincidencias.</p>}
    </div>}
  </div>
}
