import { useEffect, useId, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CheckIcon, Loader2Icon, SearchIcon } from 'lucide-react'
import { Input } from './input'
import type { SatEntry } from '@/features/fiscal/sat-catalog-api'

export function CatalogCombobox({ value, label, placeholder, disabled, allowEmpty, emptyLabel = 'Usar valor predeterminado', search, onValueChange }: {
  value: string; label?: string | null; placeholder: string; disabled?: boolean; allowEmpty?: boolean; emptyLabel?: string
  search: (query: string) => Promise<SatEntry[]>; onValueChange: (code: string, description?: string) => void
}) {
  const id = useId(); const root = useRef<HTMLDivElement>(null)
  const [query, setQuery] = useState('')
  const [selectedLabel, setSelectedLabel] = useState(label ?? '')
  const [term, setTerm] = useState(''); const [open, setOpen] = useState(false); const [active, setActive] = useState(0)
  useEffect(() => { const timer = window.setTimeout(() => setTerm(query.trim()), 250); return () => window.clearTimeout(timer) }, [query])
  useEffect(() => { const close = (event: MouseEvent) => { if (!root.current?.contains(event.target as Node)) setOpen(false) }; document.addEventListener('mousedown', close); return () => document.removeEventListener('mousedown', close) }, [])
  const result = useQuery({ queryKey: ['sat-catalog-search', search.name, term], queryFn: () => search(term), enabled: open && term.length >= 2, staleTime: 300_000 })
  const options = result.data ?? []
  function choose(entry?: SatEntry) { setSelectedLabel(entry?.description ?? ''); onValueChange(entry?.code ?? '', entry?.description); setQuery(entry ? `${entry.code} · ${entry.description}` : ''); setOpen(false) }
  return <div ref={root} className="relative">
    <div className="relative"><SearchIcon className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" /><Input role="combobox" aria-expanded={open} aria-controls={`${id}-listbox`} aria-autocomplete="list" className="pl-9" value={open ? query : value ? `${value}${(label || selectedLabel) ? ` · ${label || selectedLabel}` : ''}` : ''} disabled={disabled} placeholder={placeholder} onFocus={() => { setQuery(''); setTerm(''); setOpen(true) }} onChange={(e) => { setQuery(e.target.value); setOpen(true); setActive(0); if (value) onValueChange('') }} onKeyDown={(e) => { if (!open) return; if (e.key === 'ArrowDown') { e.preventDefault(); setActive((n) => Math.min(n + 1, options.length - 1)) } if (e.key === 'ArrowUp') { e.preventDefault(); setActive((n) => Math.max(0, n - 1)) } if (e.key === 'Enter' && options[active]) { e.preventDefault(); choose(options[active]) } if (e.key === 'Escape') setOpen(false) }} />{result.isFetching && <Loader2Icon className="absolute right-3 top-1/2 size-4 -translate-y-1/2 animate-spin text-muted-foreground" />}</div>
    {open && <div id={`${id}-listbox`} role="listbox" className="absolute z-50 mt-1 max-h-64 w-full overflow-auto rounded-md border bg-popover p-1 text-popover-foreground shadow-md">
      {allowEmpty && <button type="button" role="option" className="flex w-full items-center rounded-sm px-2 py-2 text-left text-sm hover:bg-accent" onClick={() => choose()}>{emptyLabel}</button>}
      {term.length < 2 ? <p className="px-2 py-3 text-xs text-muted-foreground">Escribe al menos dos caracteres.</p> : result.isError ? <p className="px-2 py-3 text-xs text-destructive">No pudimos consultar el catálogo.</p> : !result.isFetching && options.length === 0 ? <p className="px-2 py-3 text-xs text-muted-foreground">No encontramos coincidencias vigentes.</p> : options.map((entry, index) => <button key={entry.code} type="button" role="option" aria-selected={entry.code === value} className={`flex w-full items-start gap-2 rounded-sm px-2 py-2 text-left text-sm ${index === active ? 'bg-accent' : 'hover:bg-accent'}`} onMouseEnter={() => setActive(index)} onClick={() => choose(entry)}><CheckIcon className={`mt-0.5 size-4 shrink-0 ${entry.code === value ? 'opacity-100' : 'opacity-0'}`} /><span><strong className="font-medium">{entry.code}</strong> · {entry.description}</span></button>)}
    </div>}
  </div>
}
