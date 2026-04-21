import { useTranslation } from 'react-i18next'
import { textColors } from '@/lib/designTokens'
import { useVehicleMileageHistory } from '../../hooks/useVehicleMileageHistory'
import { MileageLogCard } from '../molecules/MileageLogCard'

interface MileageLogListProps {
  vehicleId: string
  unit?: 'km' | 'mi'
}

export function MileageLogList({ vehicleId, unit = 'km' }: MileageLogListProps) {
  const { t } = useTranslation(['vehicle-ownership', 'common'])
  const { data, isLoading, error } = useVehicleMileageHistory(vehicleId)

  if (isLoading) {
    return <div className={`text-sm ${textColors.tertiary}`}>{t('common:status.loading')}</div>
  }

  if (error !== null) {
    return <div className={`text-sm ${textColors.error}`}>{t('common:errors.loadFailed', { defaultValue: 'Failed to load' })}</div>
  }

  const readings = data ?? []
  if (readings.length === 0) {
    return <div className={`text-sm ${textColors.tertiary}`}>{t('mileage.empty')}</div>
  }

  return (
    <div className="flex flex-col gap-2">
      {readings.map((reading) => (
        <MileageLogCard key={reading.id} reading={reading} unit={unit} />
      ))}
    </div>
  )
}
