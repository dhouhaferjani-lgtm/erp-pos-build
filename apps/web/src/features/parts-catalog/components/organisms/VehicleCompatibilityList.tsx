import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { FitmentConfidenceBadge } from '../atoms/FitmentConfidenceBadge'
import type { CompatibleVehicle } from '../../types/catalog'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface VehicleCompatibilityListProps {
  vehicles: CompatibleVehicle[]
  showDataSource?: boolean
  className?: string
}

export function VehicleCompatibilityList({
  vehicles,
  showDataSource = false,
  className,
}: VehicleCompatibilityListProps) {
  const { t } = useTranslation(['parts-catalog'])

  // Group vehicles by manufacturer (first word of display string)
  const grouped = useMemo(() => {
    const groups = new Map<string, CompatibleVehicle[]>()
    for (const v of vehicles) {
      const manufacturer = v.display.split(' ')[0] ?? 'Other'
      const existing = groups.get(manufacturer) ?? []
      existing.push(v)
      groups.set(manufacturer, existing)
    }
    return groups
  }, [vehicles])

  if (vehicles.length === 0) return null

  return (
    <div className={cn('', className)}>
      <h3 className={`text-sm font-semibold ${colorTokens.text.primary} mb-3`}>
        {t('parts-catalog:article.vehicleCompatibility')}
      </h3>
      <div className="space-y-4">
        {Array.from(grouped.entries()).map(([manufacturer, vehicleList]) => (
          <div key={manufacturer}>
            <h4 className={`text-xs font-semibold ${colorTokens.text.subtle} uppercase tracking-wide mb-2`}>
              {manufacturer}
            </h4>
            <div className="space-y-1.5">
              {vehicleList.map((vehicle) => (
                <div
                  key={vehicle.vehicle_id}
                  className={`flex items-center justify-between rounded-lg ${colorTokens.surface.page} px-3.5 py-2.5`}
                >
                  <div className="min-w-0 flex-1">
                    <p className={`text-sm ${colorTokens.text.strong} truncate`}>{vehicle.display}</p>
                    {showDataSource && (
                      <p className={`text-xs ${colorTokens.text.disabled} mt-0.5`}>
                        {t('parts-catalog:fitment.dataSource', { source: vehicle.data_source })}
                      </p>
                    )}
                  </div>
                  <FitmentConfidenceBadge
                    confidence={vehicle.fitment_confidence}
                    className="ms-3 shrink-0"
                  />
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}
