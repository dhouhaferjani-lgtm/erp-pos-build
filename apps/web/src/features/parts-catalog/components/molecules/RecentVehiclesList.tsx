import { useTranslation } from 'react-i18next'
import { Clock, Car } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useVehicleStore } from '../../stores/useVehicleStore'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface RecentVehiclesListProps {
  className?: string
}

export function RecentVehiclesList({ className }: RecentVehiclesListProps) {
  const { t } = useTranslation(['parts-catalog'])
  const { vehicleHistory, selectFromHistory } = useVehicleStore()

  const formatRelativeTime = (isoDate: string): string => {
    const now = Date.now()
    const then = new Date(isoDate).getTime()
    const diffMs = now - then

    const minutes = Math.floor(diffMs / 60_000)
    if (minutes < 1) return t('parts-catalog:recentVehicles.justNow')
    if (minutes < 60) return t('parts-catalog:recentVehicles.minutesAgo', { count: minutes })

    const hours = Math.floor(minutes / 60)
    if (hours < 24) return t('parts-catalog:recentVehicles.hoursAgo', { count: hours })

    const days = Math.floor(hours / 24)
    if (days === 1) return t('parts-catalog:recentVehicles.yesterday')
    return t('parts-catalog:recentVehicles.daysAgo', { count: days })
  }

  return (
    <div className={cn('flex flex-col', className)}>
      <h3 className={`text-sm font-medium ${colorTokens.text.secondary} mb-3 flex items-center gap-2`}>
        <Clock className={`h-4 w-4 ${colorTokens.text.disabled}`} />
        {t('parts-catalog:recentVehicles.title')}
      </h3>

      {vehicleHistory.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-8">
          <Clock className={`h-12 w-12 ${colorTokens.text.faint} mb-3`} />
          <p className={`text-sm font-medium ${colorTokens.text.subtle}`}>
            {t('parts-catalog:recentVehicles.empty')}
          </p>
          <p className={`mt-1 text-xs ${colorTokens.text.disabled} text-center`}>
            {t('parts-catalog:recentVehicles.emptyHint')}
          </p>
        </div>
      ) : (
        <div className="space-y-1">
          {vehicleHistory.map((vehicle) => (
            <button
              key={vehicle.id}
              type="button"
              onClick={() => { selectFromHistory(vehicle.id) }}
              className={`w-full flex items-center gap-3 rounded-lg px-3 py-2.5 text-start ${colorTokens.intent.neutral.bgHover} transition-colors group`}
            >
              <div className={`flex items-center justify-center h-8 w-8 rounded-md ${colorTokens.surface.muted} ${colorTokens.intent.primary.groupBgSoftHover} transition-colors shrink-0`}>
                <Car className={`h-4 w-4 ${colorTokens.text.disabled} ${colorTokens.intent.primary.groupTextHover} transition-colors`} />
              </div>
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-1.5">
                  <span className={`text-sm font-semibold ${colorTokens.text.strong}`}>
                    {vehicle.manufacturerBrand}
                  </span>
                  <span className={`text-sm ${colorTokens.text.muted} truncate`}>
                    {vehicle.modelSeriesName}
                  </span>
                </div>
                <div className="flex items-center gap-2 mt-0.5">
                  <span className={`text-xs ${colorTokens.text.subtle} truncate`}>
                    {vehicle.display}
                  </span>
                  <span className={`text-xs ${colorTokens.text.disabled}`}>
                    {t('parts-catalog:recentVehicles.lastSearched')}{' '}
                    {formatRelativeTime(vehicle.selectedAt)}
                  </span>
                </div>
              </div>
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
