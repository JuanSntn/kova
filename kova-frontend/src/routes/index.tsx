import { RouterProvider } from '@tanstack/react-router'

import { router } from '@/routes/router'

export function AppRouter() {
  return <RouterProvider router={router} />
}
