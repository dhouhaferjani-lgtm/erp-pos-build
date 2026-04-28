import { useTranslation } from 'react-i18next'
import { tokens } from '../../../../lib/designTokens'
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
      <span className={`${tokens.badge.base} ${tokens.badge.gray}`}>
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
    <span className={`${tokens.badge.base} ${tokens.badge.blue}`}>
      {label}
    </span>
  )
}
