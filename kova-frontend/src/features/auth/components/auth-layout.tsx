import type { ReactNode } from 'react'

export function AuthLayout({
  children,
}: {
  children: ReactNode
}) {
  return (
    <main className="h-svh w-full overflow-hidden bg-background">
      {children}
    </main>
  )
}