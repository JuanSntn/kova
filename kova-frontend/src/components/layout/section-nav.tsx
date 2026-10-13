import type { ReactNode } from 'react'

export function SectionNav({ label, children }: { label: string; children: ReactNode }) {
  return <nav aria-label={label} className="-mb-px flex gap-5 border-b">{children}</nav>
}

export function SectionNavButton({ active, children, disabled = false, id, onClick }: {
  active: boolean
  children: ReactNode
  disabled?: boolean
  id?: string
  onClick: () => void
}) {
  return (
    <button
      type="button"
      id={id}
      aria-current={active ? 'page' : undefined}
      disabled={disabled}
      className={[
        'border-b-2 px-0 py-2.5 text-xs font-medium transition-colors',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
        'disabled:cursor-not-allowed disabled:opacity-50',
        active ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground',
      ].join(' ')}
      onClick={onClick}
    >
      {children}
    </button>
  )
}
