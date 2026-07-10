import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { ChevronRight, Search, Loader2 } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useManufacturers } from '../../hooks/useManufacturers'
import { useModelSeries } from '../../hooks/useModelSeries'
import { useVehicles } from '../../hooks/useVehicles'
import { useVehicleStore } from '../../stores/useVehicleStore'
import type { Manufacturer, ModelSeries, Vehicle, VehicleType, SelectedVehicle } from '../../types/catalog'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface VehicleNavigatorProps {
  vehicleType?: VehicleType
  onVehicleSelected?: (vehicle: SelectedVehicle) => void
  className?: string
}

type Step = 'manufacturer' | 'model' | 'vehicle'

export function VehicleNavigator({
  vehicleType = 'pc',
  onVehicleSelected,
  className,
}: VehicleNavigatorProps) {
  const { t } = useTranslation(['parts-catalog'])
  const { selectVehicle } = useVehicleStore()

  const [selectedManufacturer, setSelectedManufacturer] = useState<Manufacturer | null>(null)
  const [selectedModel, setSelectedModel] = useState<ModelSeries | null>(null)
  const [searchQuery, setSearchQuery] = useState('')

  const currentStep: Step = !selectedManufacturer
    ? 'manufacturer'
    : !selectedModel
      ? 'model'
      : 'vehicle'

  const { data: manufacturers, isLoading: loadingMfr } = useManufacturers()
  const { data: models, isLoading: loadingModels } = useModelSeries(selectedManufacturer?.id ?? '')
  const { data: vehicles, isLoading: loadingVehicles } = useVehicles(
    selectedModel?.id ?? '',
    vehicleType
  )

  const filteredManufacturers = useMemo(() => {
    if (!manufacturers) return []
    if (!searchQuery.trim()) return manufacturers
    const q = searchQuery.toLowerCase()
    return manufacturers.filter((m) => m.brand.toLowerCase().includes(q))
  }, [manufacturers, searchQuery])

  const filteredModels = useMemo(() => {
    if (!models) return []
    if (!searchQuery.trim()) return models
    const q = searchQuery.toLowerCase()
    return models.filter((m) => m.name.toLowerCase().includes(q))
  }, [models, searchQuery])

  const handleSelectManufacturer = (mfr: Manufacturer) => {
    setSelectedManufacturer(mfr)
    setSelectedModel(null)
    setSearchQuery('')
  }

  const handleSelectModel = (model: ModelSeries) => {
    setSelectedModel(model)
    setSearchQuery('')
  }

  const handleSelectVehicle = (vehicle: Vehicle) => {
    const enriched: SelectedVehicle = {
      ...vehicle,
      manufacturerBrand: selectedManufacturer?.brand ?? '',
      manufacturerSlug: selectedManufacturer?.slug ?? '',
      modelSeriesName: selectedModel?.name ?? '',
      selectedAt: new Date().toISOString(),
    }
    selectVehicle(enriched)
    onVehicleSelected?.(enriched)
  }

  const handleBack = () => {
    if (currentStep === 'vehicle') {
      setSelectedModel(null)
    } else if (currentStep === 'model') {
      setSelectedManufacturer(null)
    }
    setSearchQuery('')
  }

  return (
    <div className={cn('flex flex-col', className)}>
      <h2 className={`text-lg font-semibold ${colorTokens.text.primary} mb-4`}>
        {t('parts-catalog:vehicle.title')}
      </h2>

      {/* Breadcrumb navigation */}
      <div className="flex items-center gap-1 text-sm mb-4 min-h-[28px]">
        <button
          type="button"
          onClick={() => { setSelectedManufacturer(null); setSelectedModel(null); setSearchQuery('') }}
          className={cn(
            'rounded px-1.5 py-0.5 transition-colors',
            selectedManufacturer
              ? `${colorTokens.intent.primary.text} ${colorTokens.intent.primary.bgHover} cursor-pointer`
              : `${colorTokens.text.primary} font-medium`
          )}
        >
          {t('parts-catalog:vehicle.allManufacturers')}
        </button>
        {selectedManufacturer && (
          <>
            <ChevronRight className={`h-3.5 w-3.5 ${colorTokens.text.faint}`} />
            <button
              type="button"
              onClick={handleBack}
              className={cn(
                'rounded px-1.5 py-0.5 transition-colors',
                selectedModel
                  ? `${colorTokens.intent.primary.text} ${colorTokens.intent.primary.bgHover} cursor-pointer`
                  : `${colorTokens.text.primary} font-medium`
              )}
            >
              {selectedManufacturer.brand}
            </button>
          </>
        )}
        {selectedModel && (
          <>
            <ChevronRight className={`h-3.5 w-3.5 ${colorTokens.text.faint}`} />
            <span className={`${colorTokens.text.primary} font-medium px-1.5 py-0.5`}>
              {selectedModel.name}
            </span>
          </>
        )}
      </div>

      {/* Search input (for manufacturer and model steps) */}
      {currentStep !== 'vehicle' && (
        <div className="relative mb-3">
          <Search className={`absolute start-3 top-1/2 -translate-y-1/2 h-4 w-4 ${colorTokens.text.disabled}`} />
          <input
            type="text"
            value={searchQuery}
            onChange={(e) => { setSearchQuery(e.target.value) }}
            placeholder={
              currentStep === 'manufacturer'
                ? t('parts-catalog:vehicle.searchManufacturer')
                : t('parts-catalog:vehicle.searchModel')
            }
            className={`w-full rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.page} py-2.5 ps-9 pe-3 text-sm ${colorTokens.placeholder.textMuted} ${colorTokens.focus.primaryBorder} ${colorTokens.surface.baseOnFocus} focus:outline-none focus:ring-1 ${colorTokens.focus.primaryRing} transition-colors`}
          />
        </div>
      )}

      {/* Manufacturer list */}
      {currentStep === 'manufacturer' && (
        <div className={`space-y-0.5 max-h-[480px] overflow-y-auto rounded-lg border ${colorTokens.border.hairline}`}>
          {loadingMfr && (
            <div className="flex items-center justify-center py-12">
              <Loader2 className={`h-8 w-8 animate-spin ${colorTokens.intent.primary.text}`} />
            </div>
          )}
          {filteredManufacturers.map((mfr) => (
            <button
              key={mfr.id}
              type="button"
              onClick={() => { handleSelectManufacturer(mfr) }}
              className={`w-full flex items-center justify-between px-3.5 py-2.5 text-sm ${colorTokens.text.secondary} ${colorTokens.intent.neutral.bgHover} transition-colors text-start`}
            >
              <span className="font-medium">{mfr.brand}</span>
              <ChevronRight className={`h-4 w-4 ${colorTokens.text.faint}`} />
            </button>
          ))}
          {!loadingMfr && filteredManufacturers.length === 0 && (
            <p className={`py-8 text-center text-sm ${colorTokens.text.disabled}`}>
              {t('parts-catalog:results.noResults')}
            </p>
          )}
        </div>
      )}

      {/* Model series list */}
      {currentStep === 'model' && (
        <div className={`space-y-0.5 max-h-[480px] overflow-y-auto rounded-lg border ${colorTokens.border.hairline}`}>
          {loadingModels && (
            <div className="flex items-center justify-center py-12">
              <Loader2 className={`h-8 w-8 animate-spin ${colorTokens.intent.primary.text}`} />
            </div>
          )}
          {filteredModels.map((model) => (
            <button
              key={model.id}
              type="button"
              onClick={() => { handleSelectModel(model) }}
              className={`w-full flex items-center justify-between px-3.5 py-2.5 text-sm ${colorTokens.text.secondary} ${colorTokens.intent.neutral.bgHover} transition-colors text-start`}
            >
              <div>
                <span className="font-medium">{model.name}</span>
                {(model.production_from ?? model.production_to) && (
                  <span className={`ms-2 text-xs ${colorTokens.text.disabled}`}>
                    {t('parts-catalog:vehicle.productionYears', {
                      from: model.production_from ?? '...',
                      to: model.production_to ?? '...',
                    })}
                  </span>
                )}
              </div>
              <ChevronRight className={`h-4 w-4 ${colorTokens.text.faint}`} />
            </button>
          ))}
          {!loadingModels && filteredModels.length === 0 && (
            <p className={`py-8 text-center text-sm ${colorTokens.text.disabled}`}>
              {t('parts-catalog:vehicle.noModels')}
            </p>
          )}
        </div>
      )}

      {/* Vehicle list */}
      {currentStep === 'vehicle' && (
        <div className="space-y-1.5 max-h-[480px] overflow-y-auto">
          {loadingVehicles && (
            <div className="flex items-center justify-center py-12">
              <Loader2 className={`h-8 w-8 animate-spin ${colorTokens.intent.primary.text}`} />
            </div>
          )}
          {vehicles?.map((vehicle) => (
            <button
              key={vehicle.id}
              type="button"
              onClick={() => { handleSelectVehicle(vehicle) }}
              className={`w-full rounded-lg border ${colorTokens.border.subtle} px-4 py-3 text-start transition-all ${colorTokens.intent.primary.hoverBorderSoft} hover:${colorTokens.intent.primary.bgSubtleAlpha} focus:outline-none focus:ring-2 ${colorTokens.focus.primaryRing}`}
            >
              <p className={`text-sm font-medium ${colorTokens.text.primary}`}>{vehicle.display}</p>
              <div className={`mt-1 flex flex-wrap items-center gap-3 text-xs ${colorTokens.text.subtle}`}>
                {vehicle.power_kw && vehicle.power_hp && (
                  <span>
                    {t('parts-catalog:vehicle.power', {
                      kw: vehicle.power_kw,
                      hp: vehicle.power_hp,
                    })}
                  </span>
                )}
                {vehicle.engine_code && (
                  <span>
                    {t('parts-catalog:vehicle.engineCode', { code: vehicle.engine_code })}
                  </span>
                )}
                {(vehicle.production_from ?? vehicle.production_to) && (
                  <span>
                    {t('parts-catalog:vehicle.productionYears', {
                      from: vehicle.production_from ?? '...',
                      to: vehicle.production_to ?? '...',
                    })}
                  </span>
                )}
              </div>
            </button>
          ))}
          {!loadingVehicles && vehicles?.length === 0 && (
            <p className={`py-8 text-center text-sm ${colorTokens.text.disabled}`}>
              {t('parts-catalog:vehicle.noVehicles')}
            </p>
          )}
        </div>
      )}
    </div>
  )
}
