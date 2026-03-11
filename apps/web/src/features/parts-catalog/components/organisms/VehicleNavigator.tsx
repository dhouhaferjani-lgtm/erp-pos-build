import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { ChevronRight, Search, Loader2 } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useManufacturers } from '../../hooks/useManufacturers'
import { useModelSeries } from '../../hooks/useModelSeries'
import { useVehicles } from '../../hooks/useVehicles'
import { useVehicleStore } from '../../stores/useVehicleStore'
import type { Manufacturer, ModelSeries, Vehicle, VehicleType, SelectedVehicle } from '../../types/catalog'

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
      <h2 className="text-lg font-semibold text-gray-900 mb-4">
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
              ? 'text-blue-600 hover:bg-blue-50 cursor-pointer'
              : 'text-gray-900 font-medium'
          )}
        >
          {t('parts-catalog:vehicle.allManufacturers')}
        </button>
        {selectedManufacturer && (
          <>
            <ChevronRight className="h-3.5 w-3.5 text-gray-300" />
            <button
              type="button"
              onClick={handleBack}
              className={cn(
                'rounded px-1.5 py-0.5 transition-colors',
                selectedModel
                  ? 'text-blue-600 hover:bg-blue-50 cursor-pointer'
                  : 'text-gray-900 font-medium'
              )}
            >
              {selectedManufacturer.brand}
            </button>
          </>
        )}
        {selectedModel && (
          <>
            <ChevronRight className="h-3.5 w-3.5 text-gray-300" />
            <span className="text-gray-900 font-medium px-1.5 py-0.5">
              {selectedModel.name}
            </span>
          </>
        )}
      </div>

      {/* Search input (for manufacturer and model steps) */}
      {currentStep !== 'vehicle' && (
        <div className="relative mb-3">
          <Search className="absolute start-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
          <input
            type="text"
            value={searchQuery}
            onChange={(e) => { setSearchQuery(e.target.value) }}
            placeholder={
              currentStep === 'manufacturer'
                ? t('parts-catalog:vehicle.searchManufacturer')
                : t('parts-catalog:vehicle.searchModel')
            }
            className="w-full rounded-lg border border-gray-200 bg-gray-50 py-2.5 ps-9 pe-3 text-sm placeholder:text-gray-400 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-blue-500 transition-colors"
          />
        </div>
      )}

      {/* Manufacturer list */}
      {currentStep === 'manufacturer' && (
        <div className="space-y-0.5 max-h-[480px] overflow-y-auto rounded-lg border border-gray-100">
          {loadingMfr && (
            <div className="flex items-center justify-center py-12">
              <Loader2 className="h-8 w-8 animate-spin text-blue-600" />
            </div>
          )}
          {filteredManufacturers.map((mfr) => (
            <button
              key={mfr.id}
              type="button"
              onClick={() => { handleSelectManufacturer(mfr) }}
              className="w-full flex items-center justify-between px-3.5 py-2.5 text-sm text-gray-700 hover:bg-gray-50 transition-colors text-start"
            >
              <span className="font-medium">{mfr.brand}</span>
              <ChevronRight className="h-4 w-4 text-gray-300" />
            </button>
          ))}
          {!loadingMfr && filteredManufacturers.length === 0 && (
            <p className="py-8 text-center text-sm text-gray-400">
              {t('parts-catalog:results.noResults')}
            </p>
          )}
        </div>
      )}

      {/* Model series list */}
      {currentStep === 'model' && (
        <div className="space-y-0.5 max-h-[480px] overflow-y-auto rounded-lg border border-gray-100">
          {loadingModels && (
            <div className="flex items-center justify-center py-12">
              <Loader2 className="h-8 w-8 animate-spin text-blue-600" />
            </div>
          )}
          {filteredModels.map((model) => (
            <button
              key={model.id}
              type="button"
              onClick={() => { handleSelectModel(model) }}
              className="w-full flex items-center justify-between px-3.5 py-2.5 text-sm text-gray-700 hover:bg-gray-50 transition-colors text-start"
            >
              <div>
                <span className="font-medium">{model.name}</span>
                {(model.production_from ?? model.production_to) && (
                  <span className="ms-2 text-xs text-gray-400">
                    {t('parts-catalog:vehicle.productionYears', {
                      from: model.production_from ?? '...',
                      to: model.production_to ?? '...',
                    })}
                  </span>
                )}
              </div>
              <ChevronRight className="h-4 w-4 text-gray-300" />
            </button>
          ))}
          {!loadingModels && filteredModels.length === 0 && (
            <p className="py-8 text-center text-sm text-gray-400">
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
              <Loader2 className="h-8 w-8 animate-spin text-blue-600" />
            </div>
          )}
          {vehicles?.map((vehicle) => (
            <button
              key={vehicle.id}
              type="button"
              onClick={() => { handleSelectVehicle(vehicle) }}
              className="w-full rounded-lg border border-gray-200 px-4 py-3 text-start transition-all hover:border-blue-200 hover:bg-blue-50/50 focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <p className="text-sm font-medium text-gray-900">{vehicle.display}</p>
              <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-gray-500">
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
            <p className="py-8 text-center text-sm text-gray-400">
              {t('parts-catalog:vehicle.noVehicles')}
            </p>
          )}
        </div>
      )}
    </div>
  )
}
