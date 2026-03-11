import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Car, RefreshCw, X, AlertTriangle } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useVehicleStore } from '../../stores/useVehicleStore'

interface StickyVehicleBarProps {
  onChangeVehicle: () => void
  className?: string
}

export function StickyVehicleBar({ onChangeVehicle, className }: StickyVehicleBarProps) {
  const { t } = useTranslation(['parts-catalog'])
  const { selectedVehicle, clearVehicle } = useVehicleStore()
  const [showClearConfirm, setShowClearConfirm] = useState(false)

  if (!selectedVehicle) return null

  const handleClear = () => {
    setShowClearConfirm(true)
  }

  const confirmClear = () => {
    clearVehicle()
    setShowClearConfirm(false)
  }

  const cancelClear = () => {
    setShowClearConfirm(false)
  }

  return (
    <div className={cn('relative', className)}>
      <div className="rounded-lg border border-blue-200 bg-blue-50/50 px-4 py-3">
        <div className="flex items-center justify-between gap-4">
          {/* Vehicle info */}
          <div className="flex items-center gap-3 min-w-0">
            <div className="flex items-center justify-center h-9 w-9 rounded-lg bg-blue-100 shrink-0">
              <Car className="h-5 w-5 text-blue-600" />
            </div>
            <div className="min-w-0">
              <div className="flex items-center gap-2 flex-wrap">
                <span className="text-sm font-bold text-blue-900">
                  {selectedVehicle.manufacturerBrand}
                </span>
                <span className="text-sm font-medium text-blue-800">
                  {selectedVehicle.modelSeriesName}
                </span>
                <span className="text-sm text-blue-700">
                  {selectedVehicle.display}
                </span>
              </div>
              <div className="flex items-center gap-3 text-xs text-blue-600 mt-0.5 flex-wrap">
                {selectedVehicle.power_kw !== null && selectedVehicle.power_hp !== null && (
                  <span>
                    {t('parts-catalog:vehicle.power', {
                      kw: selectedVehicle.power_kw,
                      hp: selectedVehicle.power_hp,
                    })}
                  </span>
                )}
                {selectedVehicle.engine_code && (
                  <span>
                    {t('parts-catalog:vehicle.engineCode', {
                      code: selectedVehicle.engine_code,
                    })}
                  </span>
                )}
                {(selectedVehicle.production_from ?? selectedVehicle.production_to) && (
                  <span>
                    {t('parts-catalog:vehicle.productionYears', {
                      from: selectedVehicle.production_from ?? '...',
                      to: selectedVehicle.production_to ?? '...',
                    })}
                  </span>
                )}
                {selectedVehicle.plate && (
                  <span className="font-mono font-medium">{selectedVehicle.plate}</span>
                )}
                {selectedVehicle.vin && (
                  <span className="font-mono text-[11px]">{selectedVehicle.vin}</span>
                )}
              </div>
            </div>
          </div>

          {/* Actions */}
          <div className="flex items-center gap-2 shrink-0">
            <button
              type="button"
              onClick={onChangeVehicle}
              className="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium text-blue-700 hover:bg-blue-100 transition-colors"
            >
              <RefreshCw className="h-3.5 w-3.5" />
              {t('parts-catalog:stickyBar.change')}
            </button>
            <button
              type="button"
              onClick={handleClear}
              className="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 transition-colors"
            >
              <X className="h-3.5 w-3.5" />
              {t('parts-catalog:stickyBar.clear')}
            </button>
          </div>
        </div>
      </div>

      {/* Clear confirmation dialog */}
      {showClearConfirm && (
        <div className="absolute inset-x-0 top-full mt-2 z-10">
          <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 shadow-lg">
            <div className="flex items-start gap-3">
              <AlertTriangle className="h-5 w-5 text-amber-500 shrink-0 mt-0.5" />
              <div>
                <p className="text-sm font-medium text-amber-900">
                  {t('parts-catalog:stickyBar.clearConfirmTitle')}
                </p>
                <p className="text-xs text-amber-700 mt-1">
                  {t('parts-catalog:stickyBar.clearConfirmMessage')}
                </p>
                <div className="flex items-center gap-2 mt-3">
                  <button
                    type="button"
                    onClick={confirmClear}
                    className="rounded-md bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-700 transition-colors"
                  >
                    {t('parts-catalog:stickyBar.confirm')}
                  </button>
                  <button
                    type="button"
                    onClick={cancelClear}
                    className="rounded-md bg-white px-3 py-1.5 text-xs font-medium text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 transition-colors"
                  >
                    {t('parts-catalog:stickyBar.cancel')}
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
