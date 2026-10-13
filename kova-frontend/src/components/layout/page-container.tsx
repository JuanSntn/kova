import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

export function PageContainer({
  children,
  className,
  ...props
}: React.ComponentProps<'main'> & { children: ReactNode }) {
  return (
    <main
      className={cn(
        'mx-auto flex min-w-0 w-full max-w-screen-2xl',
        'flex-1 flex-col gap-6',
        'px-4 py-6',
        'sm:px-6',
        'lg:px-8 lg:py-8',
        className,
      )}
      {...props}
    >
      {children}
    </main>
  )
}
