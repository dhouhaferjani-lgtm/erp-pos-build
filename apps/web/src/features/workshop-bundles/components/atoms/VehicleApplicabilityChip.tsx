import { useTranslation } from 'react-i18next'
import type { ServiceBundleVehicleApplicabilityData } from '../../types'

interface VehicleApplicabilityChipProps {
  applicability: ServiceBundleVehicleApplicabilityData
}

export function VehicleApplicabilityChip({
  applicability,
}: VehicleApplicabilityChipProps) {
  const { t } = useTranslation('workshop-bundles')

  if (applicability.platform_vehicle_id === null) {
    return (
      <span className="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">
        {t('applicability.universal')}
      </span>
    )
  }

  const label =
    applicability.vehicle_display ??
    t('applicability.vehicleFallback', {
      type: applicability.vehicle_type ?? 'pc',
      id: applicability.platform_vehicle_id,
    })

  return (
    <span className="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700">
      {label}
    </span>
  )
}
