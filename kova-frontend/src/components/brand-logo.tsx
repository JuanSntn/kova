import { Link } from '@tanstack/react-router'

import { cn } from '@/lib/utils'

type BrandLogoProps = {
  className?: string
  compact?: boolean
  to?: '/login'
}

export function BrandLogo({ className, compact = false, to = '/login' }: BrandLogoProps) {
  return (
    <Link
      to={to}
      className={cn(
        'inline-flex w-fit items-center gap-2.5 rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
        className,
      )}
      aria-label="Kova"
    >
      <img
        src="/brand/kova-logo.png"
        alt=""
        width={40}
        height={40}
        className="h-9 w-9 shrink-0 object-contain"
      />
      {!compact && (
        <span className="flex flex-col">
          <span className="text-base font-semibold leading-5 tracking-tight">Kova</span>
          <span className="text-[11px] leading-4 text-muted-foreground">Administración</span>
        </span>
      )}
    </Link>
  )
}
