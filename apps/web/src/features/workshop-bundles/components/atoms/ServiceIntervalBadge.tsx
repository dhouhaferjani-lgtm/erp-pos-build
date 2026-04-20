import { useTranslation } from 'react-i18next'

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
    <span className="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">
      {parts.join(' · ')}
    </span>
  )
}
