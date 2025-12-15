import { useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { useCompany } from '../../../hooks/useCompany'
import { MarginIndicator, type MarginLevel } from '../MarginIndicator'

export interface PriceInputWithMarginProps {
  value: number
  onChange: (value: number) => void
  costPrice: number
  targetMargin: number
  minimumMargin: number
  currency?: string
  disabled?: boolean
  label?: string
  showMarginInput?: boolean
  canEditBelowMinimum?: boolean
  canEditAtLoss?: boolean
}

function calculateMargin(salePrice: number, costPrice: number): number | null {
  if (costPrice <= 0) return null
  return ((salePrice - costPrice) / salePrice) * 100
}

function calculatePriceFromMargin(costPrice: number, margin: number): number {
  if (margin >= 100) return costPrice * 10 // Cap at very high price
  return costPrice / (1 - margin / 100)
}

function getMarginLevel(
  margin: number | null,
  targetMargin: number,
  minimumMargin: number
): MarginLevel {
  if (margin === null) return 'red'
  if (margin < 0) return 'red'
  if (margin < minimumMargin) return 'orange'
  if (margin < targetMargin) return 'yellow'
  return 'green'
}

export function PriceInputWithMargin({
  value,
  onChange,
  costPrice,
  targetMargin,
  minimumMargin,
  currency,
  disabled = false,
  label,
  showMarginInput = true,
  canEditBelowMinimum = true,
  canEditAtLoss = false,
}: PriceInputWithMarginProps) {
  const { t } = useTranslation(['inventory'])
  const { currentCompany } = useCompany()
  const effectiveCurrency = currency ?? currentCompany?.currency ?? 'USD'

  const margin = calculateMargin(value, costPrice)
  const level = getMarginLevel(margin, targetMargin, minimumMargin)
  const lossAmount = margin !== null && margin < 0 ? costPrice - value : undefined

  const getMessage = (): string => {
    switch (level) {
      case 'green':
        return t('inventory:margin.aboveTarget')
      case 'yellow':
        return t('inventory:margin.belowTarget')
      case 'orange':
        return t('inventory:margin.belowMinimum')
      case 'red':
        return t('inventory:margin.sellingAtLoss')
    }
  }

  const handlePriceChange = useCallback(
    (newPrice: number) => {
      const newMargin = calculateMargin(newPrice, costPrice)

      // Check permissions
      if (!canEditAtLoss && newMargin !== null && newMargin < 0) {
        // Don't allow going below cost
        onChange(costPrice)
        return
      }

      if (!canEditBelowMinimum && newMargin !== null && newMargin < minimumMargin) {
        // Snap to minimum margin
        const minPrice = calculatePriceFromMargin(costPrice, minimumMargin)
        onChange(Math.round(minPrice * 100) / 100)
        return
      }

      onChange(newPrice)
    },
    [costPrice, minimumMargin, canEditAtLoss, canEditBelowMinimum, onChange]
  )

  const handleMarginChange = useCallback(
    (newMargin: number) => {
      // Check permissions
      if (!canEditAtLoss && newMargin < 0) {
        newMargin = 0
      }

      if (!canEditBelowMinimum && newMargin < minimumMargin) {
        newMargin = minimumMargin
      }

      const newPrice = calculatePriceFromMargin(costPrice, newMargin)
      onChange(Math.round(newPrice * 100) / 100)
    },
    [costPrice, minimumMargin, canEditAtLoss, canEditBelowMinimum, onChange]
  )

  const suggestedPrice = calculatePriceFromMargin(costPrice, targetMargin)

  const formatCurrency = (val: number): string => {
    return new Intl.NumberFormat('fr-TN', {
      style: 'currency',
      currency: effectiveCurrency,
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(val)
  }

  return (
    <div className="space-y-2">
      {label && (
        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
          {label}
        </label>
      )}

      <div className="flex items-center gap-2">
        {/* Price Input */}
        <div className="flex-1">
          <div className="relative">
            <input
              type="number"
              value={value || ''}
              onChange={(e) => { handlePriceChange(parseFloat(e.target.value) || 0) }}
              disabled={disabled}
              step="0.01"
              min="0"
              className={`w-full rounded-md border px-3 py-2 pe-12 text-end focus:outline-none focus:ring-1 ${
                level === 'red'
                  ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
                  : level === 'orange'
                  ? 'border-orange-300 focus:border-orange-500 focus:ring-orange-500'
                  : 'border-gray-300 focus:border-blue-500 focus:ring-blue-500'
              } dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100`}
            />
            <span className="absolute end-3 top-1/2 -translate-y-1/2 text-sm text-gray-500">
              {effectiveCurrency}
            </span>
          </div>
        </div>

        {/* Margin Input */}
        {showMarginInput && (
          <div className="w-24">
            <div className="relative">
              <input
                type="number"
                value={margin !== null ? margin.toFixed(1) : ''}
                onChange={(e) => { handleMarginChange(parseFloat(e.target.value) || 0) }}
                disabled={disabled || costPrice <= 0}
                step="0.1"
                className={`w-full rounded-md border px-3 py-2 pe-6 text-end focus:outline-none focus:ring-1 ${
                  level === 'red'
                    ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
                    : level === 'orange'
                    ? 'border-orange-300 focus:border-orange-500 focus:ring-orange-500'
                    : 'border-gray-300 focus:border-blue-500 focus:ring-blue-500'
                } dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100`}
              />
              <span className="absolute end-2 top-1/2 -translate-y-1/2 text-sm text-gray-500">
                %
              </span>
            </div>
          </div>
        )}
      </div>

      {/* Margin Indicator */}
      <MarginIndicator
        level={level}
        actualMargin={margin}
        message={getMessage()}
        targetMargin={targetMargin}
        minimumMargin={minimumMargin}
        {...(lossAmount !== undefined && { lossAmount })}
        showDetails={true}
        compact={false}
      />

      {/* Suggested Price */}
      {costPrice > 0 && value !== suggestedPrice && level !== 'green' && (
        <button
          type="button"
          onClick={() => { onChange(Math.round(suggestedPrice * 100) / 100) }}
          disabled={disabled}
          className="text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
        >
          {t('inventory:pricing.suggestedPrice')}: {formatCurrency(suggestedPrice)}
        </button>
      )}
    </div>
  )
}

export default PriceInputWithMargin
