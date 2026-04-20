import { useTranslation } from 'react-i18next'
import { tokens } from '../../../../lib/designTokens'

interface ServiceIntervalBadgeProps {
  km: number | null
  months: number | null
}

export function ServiceIntervalBadge({
  km,
  months,
}: ServiceIntervalBadgeProps) {
  const { t } = useTranslation('workshop-bundles')

  if (km === null && months === null) {
    return null
  }

  const parts: string[] = []
  if (km !== null) {
    parts.push(t('interval.km', { count: km }))
  }
  if (months !== null) {
    parts.push(t('interval.months', { count: months }))
  }

  return (
    <span className={`${tokens.badge.base} ${tokens.badge.green}`}>
      {parts.join(' · ')}
    </span>
  )
}
