import { useTranslation } from 'react-i18next'
import { borderColors, textColors } from '@/lib/designTokens'
import type { VehicleMileageReadingData } from '../../types'

interface MileageLogCardProps {
  reading: VehicleMileageReadingData
  unit?: 'km' | 'mi'
}

export function MileageLogCard({ reading, unit = 'km' }: MileageLogCardProps) {
  const { t } = useTranslation('vehicle-ownership')

  const formattedMileage = new Intl.NumberFormat(undefined).format(reading.mileage)
  const recordedDate = new Date(reading.recorded_at).toLocaleString()

  return (
    <div className={`flex items-center justify-between rounded border px-3 py-2 ${borderColors.light}`}>
      <div className="flex flex-col gap-0.5">
        <span className={`text-sm font-medium ${textColors.primary}`}>
          {formattedMileage} {unit}
        </span>
        <span className={`text-xs ${textColors.tertiary}`}>{recordedDate}</span>
      </div>
      <span className={`text-xs ${textColors.tertiary}`}>
        {t(`mileageSource.${reading.source}`)}
      </span>
    </div>
  )
}
