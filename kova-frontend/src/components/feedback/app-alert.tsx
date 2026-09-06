import type { ReactNode } from 'react'
import {
  AlertTriangleIcon,
  CheckCircle2Icon,
  InfoIcon,
  XCircleIcon,
} from 'lucide-react'

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { cn } from '@/lib/utils'

type AppAlertVariant = 'info' | 'success' | 'warning' | 'error'

const variantStyles: Record<
  AppAlertVariant,
  { alertClassName?: string; icon: ReactNode }
> = {
  info: {
    icon: <InfoIcon aria-hidden />,
  },
  success: {
    alertClassName: 'border-emerald-200 bg-emerald-50',
    icon: <CheckCircle2Icon className="text-emerald-600" aria-hidden />,
  },
  warning: {
    alertClassName: 'border-amber-200 bg-amber-50',
    icon: <AlertTriangleIcon className="text-amber-600" aria-hidden />,
  },
  error: {
    alertClassName: 'border-red-200 bg-red-50',
    icon: <XCircleIcon className="text-red-600" aria-hidden />,
  },
}

export function AppAlert({
  variant = 'info',
  title,
  children,
  className,
}: {
  variant?: AppAlertVariant
  title?: string
  children?: ReactNode
  className?: string
}) {
  const styles = variantStyles[variant]

  return (
    <Alert className={cn(styles.alertClassName, className)}>
      {styles.icon}
      {title && <AlertTitle>{title}</AlertTitle>}
      {children && <AlertDescription>{children}</AlertDescription>}
    </Alert>
  )
}