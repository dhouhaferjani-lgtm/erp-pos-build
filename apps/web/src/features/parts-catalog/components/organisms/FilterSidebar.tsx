import { useTranslation } from 'react-i18next'
import { Filter, RotateCcw, X } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useCatalogStore, type CatalogFilters } from '../../stores/useCatalogStore'
import { useSupplierBrands } from '../../hooks/useSupplierBrands'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface FilterSidebarProps {
  variant?: 'inline' | 'drawer'
  open?: boolean
  onClose?: () => void
  className?: string
}

type SortOption = CatalogFilters['sortBy']

const SORT_OPTIONS: SortOption[] = [
  'relevance',
  'price_asc',
  'price_desc',
  'brand_asc',
  'confidence_desc',
]

function FilterContent({ className }: { className?: string | undefined }) {
  const { t } = useTranslation(['parts-catalog'])
  const { filters, updateFilters, resetFilters } = useCatalogStore()
  const { data: suppliers } = useSupplierBrands()

  const handleSupplierToggle = (supplierId: string) => {
    const current = filters.supplierIds
    const updated = current.includes(supplierId)
      ? current.filter((id) => id !== supplierId)
      : [...current, supplierId]
    updateFilters({ supplierIds: updated })
  }

  const hasActiveFilters =
    filters.supplierIds.length > 0 ||
    filters.inStockOnly ||
    filters.confidenceMin > 0 ||
    filters.sortBy !== 'relevance'

  return (
    <div className={cn('flex flex-col gap-5', className)}>
      {/* Header */}
      <div className="flex items-center justify-between">
        <h3 className={`text-sm font-medium ${colorTokens.text.secondary} flex items-center gap-2`}>
          <Filter className={`h-4 w-4 ${colorTokens.text.disabled}`} />
          {t('parts-catalog:filters.title')}
        </h3>
        {hasActiveFilters && (
          <button
            type="button"
            onClick={resetFilters}
            className={`inline-flex items-center gap-1 text-xs ${colorTokens.intent.primary.text} ${colorTokens.intent.primary.textHoverStrong} transition-colors`}
          >
            <RotateCcw className="h-3 w-3" />
            {t('parts-catalog:filters.reset')}
          </button>
        )}
      </div>

      {/* Sort */}
      <div>
        <label className={`block text-xs font-medium ${colorTokens.text.subtle} mb-1.5`}>
          {t('parts-catalog:filters.sortBy')}
        </label>
        <select
          value={filters.sortBy}
          onChange={(e) => { updateFilters({ sortBy: e.target.value as SortOption }) }}
          className={`w-full rounded-md border ${colorTokens.border.subtle} ${colorTokens.surface.base} py-1.5 px-2.5 text-sm ${colorTokens.text.secondary} ${colorTokens.focus.primaryBorder} focus:outline-none focus:ring-1 ${colorTokens.focus.primaryRing}`}
        >
          {SORT_OPTIONS.map((option) => (
            <option key={option} value={option}>
              {t(`parts-catalog:filters.sort.${option}`)}
            </option>
          ))}
        </select>
      </div>

      {/* In-stock toggle */}
      <label className="flex items-center gap-2 cursor-pointer">
        <input
          type="checkbox"
          checked={filters.inStockOnly}
          onChange={(e) => { updateFilters({ inStockOnly: e.target.checked }) }}
          className={`rounded ${colorTokens.border.default} ${colorTokens.intent.primary.text} ${colorTokens.focus.primaryRing}`}
        />
        <span className={`text-sm ${colorTokens.text.secondary}`}>{t('parts-catalog:filters.inStockOnly')}</span>
      </label>

      {/* Supplier brand multi-select */}
      {suppliers && suppliers.length > 0 && (
        <div>
          <label className={`block text-xs font-medium ${colorTokens.text.subtle} mb-1.5`}>
            {t('parts-catalog:filters.supplierBrand')}
          </label>
          <div className={`space-y-1 max-h-48 overflow-y-auto rounded-md border ${colorTokens.border.hairline} p-2`}>
            {suppliers.map((supplier) => (
              <label
                key={supplier.id}
                className={`flex items-center gap-2 cursor-pointer py-1 px-1 rounded ${colorTokens.intent.neutral.bgHover} transition-colors`}
              >
                <input
                  type="checkbox"
                  checked={filters.supplierIds.includes(supplier.id)}
                  onChange={() => { handleSupplierToggle(supplier.id) }}
                  className={`rounded ${colorTokens.border.default} ${colorTokens.intent.primary.text} ${colorTokens.focus.primaryRing}`}
                />
                <span className={`text-sm ${colorTokens.text.secondary}`}>{supplier.brand}</span>
              </label>
            ))}
          </div>
        </div>
      )}

      {/* Confidence minimum slider */}
      <div>
        <label className={`block text-xs font-medium ${colorTokens.text.subtle} mb-1.5`}>
          {t('parts-catalog:filters.confidenceMin')}: {filters.confidenceMin}%
        </label>
        <input
          type="range"
          min={0}
          max={100}
          step={10}
          value={filters.confidenceMin}
          onChange={(e) => { updateFilters({ confidenceMin: Number(e.target.value) }) }}
          className={`w-full ${colorTokens.intent.primary.accent}`}
        />
      </div>
    </div>
  )
}

export function FilterSidebar({ variant = 'inline', open = false, onClose, className }: FilterSidebarProps) {
  const { t } = useTranslation(['parts-catalog'])

  if (variant === 'drawer') {
    if (!open) return null
    return (
      <div className="fixed inset-0 z-50 flex">
        {/* Backdrop */}
        <button
          type="button"
          className={`fixed inset-0 ${colorTokens.surface.overlaySubtle}`}
          onClick={onClose}
          aria-label={t('parts-catalog:stickyBar.cancel')}
        />
        {/* Panel */}
        <div className={`relative ms-auto w-80 max-w-[85vw] ${colorTokens.surface.base} shadow-xl flex flex-col h-full`}>
          <div className={`flex items-center justify-between px-4 py-3 border-b ${colorTokens.border.subtle}`}>
            <h2 className={`text-base font-semibold ${colorTokens.text.primary} flex items-center gap-2`}>
              <Filter className={`h-4 w-4 ${colorTokens.text.disabled}`} />
              {t('parts-catalog:filters.title')}
            </h2>
            <button
              type="button"
              onClick={onClose}
              className={`rounded-md p-1.5 ${colorTokens.text.disabled} ${colorTokens.intent.neutral.textHover} ${colorTokens.intent.neutral.bgHoverSoft} transition-colors`}
            >
              <X className="h-5 w-5" />
            </button>
          </div>
          <div className="flex-1 overflow-y-auto p-4">
            <FilterContent />
          </div>
        </div>
      </div>
    )
  }

  return <FilterContent className={className} />
}
