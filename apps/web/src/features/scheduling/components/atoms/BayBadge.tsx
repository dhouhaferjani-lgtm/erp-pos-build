import { useTranslation } from 'react-i18next'
import { tokens } from '@/lib/designTokens'

interface BayBadgeProps {
  name: string | null
  code?: string | null
}

/**
 * Small pill identifying a bay. When `name` is null, displays "Unassigned"
 * via the `scheduler.unassignedBay` i18n key. Atom: presentational only.
 */
export function BayBadge({ name, code }: BayBadgeProps) {
  const { t } = useTranslation('scheduling')
  const label = name ?? t('scheduler.unassignedBay')
  return (
    <span className={`${tokens.badge.base} ${name === null ? tokens.badge.gray : tokens.badge.blue}`}>
      {code !== null && code !== undefined && code !== '' ? `${code} · ${label}` : label}
    </span>
  )
}
