import { useTranslation } from 'react-i18next'
import { textColors } from '@/lib/designTokens'
import { useVehicleOwnershipHistory } from '../../hooks/useVehicleOwnershipHistory'
import { OwnershipTimelineEntry } from '../molecules/OwnershipTimelineEntry'

interface OwnershipHistoryTimelineProps {
  vehicleId: string
}

export function OwnershipHistoryTimeline({ vehicleId }: OwnershipHistoryTimelineProps) {
  const { t } = useTranslation(['vehicle-ownership', 'common'])
  const { data, isLoading, error } = useVehicleOwnershipHistory(vehicleId)

  if (isLoading) {
    return <div className={`text-sm ${textColors.tertiary}`}>{t('common:status.loading')}</div>
  }

  if (error !== null) {
    return <div className={`text-sm ${textColors.error}`}>{t('common:errors.loadFailed', { defaultValue: 'Failed to load' })}</div>
  }

  const entries = data ?? []
  if (entries.length === 0) {
    return <div className={`text-sm ${textColors.tertiary}`}>{t('ownership.noOwner')}</div>
  }

  return (
    <ul className="flex flex-col gap-2">
      {entries.map((ownership) => (
        <OwnershipTimelineEntry key={ownership.id} ownership={ownership} />
      ))}
    </ul>
  )
}
