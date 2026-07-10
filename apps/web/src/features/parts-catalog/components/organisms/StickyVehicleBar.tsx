import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Car, RefreshCw, X, AlertTriangle } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useVehicleStore } from '../../stores/useVehicleStore'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

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
      <div className={`rounded-lg border ${colorTokens.intent.primary.borderSubtleSoft} ${colorTokens.intent.primary.bgSubtleAlpha} px-4 py-3`}>
        <div className="flex items-center justify-between gap-4">
          {/* Vehicle info */}
          <div className="flex items-center gap-3 min-w-0">
            <div className={`flex items-center justify-center h-9 w-9 rounded-lg ${colorTokens.intent.primary.bgSoft} shrink-0`}>
              <Car className={`h-5 w-5 ${colorTokens.intent.primary.text}`} />
            </div>
            <div className="min-w-0">
              <div className="flex items-center gap-2 flex-wrap">
                <span className={`text-sm font-bold ${colorTokens.intent.primary.textStrongest}`}>
                  {selectedVehicle.manufacturerBrand}
                </span>
                <span className={`text-sm font-medium ${colorTokens.intent.primary.textStronger}`}>
                  {selectedVehicle.modelSeriesName}
                </span>
                <span className={`text-sm ${colorTokens.intent.primary.textStrong}`}>
                  {selectedVehicle.display}
                </span>
              </div>
              <div className={`flex items-center gap-3 text-xs ${colorTokens.intent.primary.text} mt-0.5 flex-wrap`}>
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
              className={`inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium ${colorTokens.intent.primary.textStrong} ${colorTokens.intent.primary.bgSoftHover} transition-colors`}
            >
              <RefreshCw className="h-3.5 w-3.5" />
              {t('parts-catalog:stickyBar.change')}
            </button>
            <button
              type="button"
              onClick={handleClear}
              className={`inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium ${colorTokens.intent.danger.text} ${colorTokens.intent.danger.bgHover} transition-colors`}
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
          <div className={`rounded-lg border ${colorTokens.intent.caution.borderSubtle} ${colorTokens.intent.caution.bgSubtle} p-4 shadow-lg`}>
            <div className="flex items-start gap-3">
              <AlertTriangle className={`h-5 w-5 ${colorTokens.intent.caution.textSubtle} shrink-0 mt-0.5`} />
              <div>
                <p className={`text-sm font-medium ${colorTokens.intent.caution.textStrongest}`}>
                  {t('parts-catalog:stickyBar.clearConfirmTitle')}
                </p>
                <p className={`text-xs ${colorTokens.intent.caution.textStrong} mt-1`}>
                  {t('parts-catalog:stickyBar.clearConfirmMessage')}
                </p>
                <div className="flex items-center gap-2 mt-3">
                  <button
                    type="button"
                    onClick={confirmClear}
                    className={`rounded-md ${colorTokens.intent.danger.bgStrong} px-3 py-1.5 text-xs font-medium ${colorTokens.text.inverse} ${colorTokens.intent.danger.bgStrongHover} transition-colors`}
                  >
                    {t('parts-catalog:stickyBar.confirm')}
                  </button>
                  <button
                    type="button"
                    onClick={cancelClear}
                    className={`rounded-md ${colorTokens.surface.base} px-3 py-1.5 text-xs font-medium ${colorTokens.text.secondary} ring-1 ${colorTokens.intent.neutral.ring} ${colorTokens.intent.neutral.bgHover} transition-colors`}
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
