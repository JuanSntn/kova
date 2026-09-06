import {
  siAmericanexpress,
  siDinersclub,
  siDiscover,
  siMastercard,
  siVisa,
} from 'simple-icons'

export function CardBrandIcon({
  brand,
  className,
}: {
  brand?: string | null
  className?: string
}) {
  const normalized = brand?.toLowerCase()?.replace(/\s+/g, '') ?? ''

  let icon: { path: string; hex: string } | null = null

  if (normalized === 'visa') {
    icon = siVisa
  } else if (normalized === 'mastercard') {
    icon = siMastercard
  } else if (normalized === 'amex' || normalized === 'americanexpress') {
    icon = siAmericanexpress
  } else if (normalized === 'diners' || normalized === 'dinersclub') {
    icon = siDinersclub
  } else if (normalized === 'discover') {
    icon = siDiscover
  }

  if (icon) {
    return (
      <svg
        aria-hidden
        className={className}
        viewBox="0 0 24 24"
        fill={`#${icon.hex}`}
        xmlns="http://www.w3.org/2000/svg"
      >
        <path d={icon.path} />
      </svg>
    )
  }

  // Default: generic credit card icon
  return (
    <svg
      aria-hidden
      className={className}
      viewBox="0 0 48 48"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
    >
      <rect width="48" height="48" rx="8" fill="#E2E8F0" />
      <rect x="8" y="16" width="32" height="20" rx="3" fill="#94A3B8" />
      <rect x="12" y="30" width="8" height="2" rx="1" fill="#CBD5E1" />
      <rect x="12" y="22" width="20" height="2" rx="1" fill="#CBD5E1" />
      <circle cx="32" cy="31" r="4" fill="#CBD5E1" />
    </svg>
  )
}