import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { borderColors, textColors } from '@/lib/designTokens'
import { usePartnerVehicles } from '../../hooks/usePartnerVehicles'
import { MileageBadge } from '../atoms/MileageBadge'
import { FuelTypeIcon } from '../atoms/FuelTypeIcon'
import { TransmissionBadge } from '../atoms/TransmissionBadge'

interface VehiclesTabProps {
  partnerId: string
}

export function VehiclesTab({ partnerId }: VehiclesTabProps) {
  const { t } = useTranslation(['vehicle-ownership', 'common'])
  const { data, isLoading, error } = usePartnerVehicles(partnerId)

  if (isLoading) {
    return <div className={`text-sm ${textColors.tertiary}`}>{t('common:status.loading')}</div>
  }

  if (error !== null) {
    return (
      <div className={`text-sm ${textColors.error}`} role="alert">
        {t('common:errors.loadFailed', { defaultValue: 'Failed to load' })}
      </div>
    )
  }

  const vehicles = data?.data ?? []

  if (vehicles.length === 0) {
    return <div className={`text-sm ${textColors.tertiary}`}>{t('partnerVehicles.empty')}</div>
  }

  return (
    <ul className="flex flex-col gap-2">
      {vehicles.map((v) => (
        <li
          key={v.id}
          className={`flex items-center justify-between rounded border px-3 py-2 ${borderColors.light}`}
        >
          <Link to={`/vehicles/${v.id}`} className={`text-sm font-medium ${textColors.primary}`}>
            {v.license_plate} — {v.brand} {v.model}
            {v.year !== null ? ` (${String(v.year)})` : ''}
          </Link>
          <div className="flex items-center gap-2">
            <MileageBadge mileage={v.mileage} />
            <FuelTypeIcon fuelType={v.fuel_type} />
            <TransmissionBadge transmission={v.transmission} />
          </div>
        </li>
      ))}
    </ul>
  )
}
