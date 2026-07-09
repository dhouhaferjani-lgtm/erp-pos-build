import { useTranslation } from 'react-i18next'
import { useCompany } from '../../../hooks/useCompany'
import { formatPercent } from '../../../lib/format'
import { PriceInputWithMargin } from '../../molecules/PriceInputWithMargin'

export interface ProductPricingCardProps {
  productName: string
  sku?: string
  weightedAverageCost: number
  lastPurchasePrice: number
  salePrice: number
  onSalePriceChange: (price: number) => void
  targetMargin: number
  minimumMargin: number
  currency?: string
  disabled?: boolean
  canEditBelowMinimum?: boolean
  canEditAtLoss?: boolean
}

export function ProductPricingCard({
  productName,
  sku,
  weightedAverageCost,
  lastPurchasePrice,
  salePrice,
  onSalePriceChange,
  targetMargin,
  minimumMargin,
  currency,
  disabled = false,
  canEditBelowMinimum = true,
  canEditAtLoss = false,
}: ProductPricingCardProps) {
  const { t } = useTranslation(['inventory'])
  const { currentCompany } = useCompany()
  const effectiveCurrency = currency ?? currentCompany?.currency ?? 'USD'

  const formatCurrency = (value: number): string => {
    return new Intl.NumberFormat('fr-TN', {
      style: 'currency',
      currency: effectiveCurrency,
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(value)
  }

  return (
    <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
      {/* Header */}
      <div className="mb-4">
        <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">
          {productName}
        </h3>
        {sku && (
          <p className="text-sm text-gray-500 dark:text-gray-400">
            {t('inventory:products.sku')}: {sku}
          </p>
        )}
      </div>

      {/* Cost Information */}
      <div className="mb-4 grid grid-cols-2 gap-4 rounded-lg bg-gray-50 p-3 dark:bg-gray-700/50">
        <div>
          <span className="text-xs text-gray-500 dark:text-gray-400">
            {t('inventory:pricing.wac')}
          </span>
          <p className="text-lg font-semibold text-gray-900 dark:text-gray-100">
            {formatCurrency(weightedAverageCost)}
          </p>
        </div>
        <div>
          <span className="text-xs text-gray-500 dark:text-gray-400">
            {t('inventory:pricing.lastPurchasePrice')}
          </span>
          <p className="text-lg font-semibold text-gray-900 dark:text-gray-100">
            {formatCurrency(lastPurchasePrice)}
          </p>
        </div>
      </div>

      {/* Margin Settings */}
      <div className="mb-4 flex items-center gap-4 text-sm">
        <div className="flex items-center gap-2">
          <span className="text-gray-500 dark:text-gray-400">
            {t('inventory:pricing.targetMargin')}:
          </span>
          <span className="font-medium text-gray-900 dark:text-gray-100">
            {formatPercent(targetMargin)}
          </span>
        </div>
        <div className="flex items-center gap-2">
          <span className="text-gray-500 dark:text-gray-400">
            {t('inventory:pricing.minimumMargin')}:
          </span>
          <span className="font-medium text-gray-900 dark:text-gray-100">
            {formatPercent(minimumMargin)}
          </span>
        </div>
      </div>

      {/* Price Input */}
      <PriceInputWithMargin
        value={salePrice}
        onChange={onSalePriceChange}
        costPrice={weightedAverageCost}
        targetMargin={targetMargin}
        minimumMargin={minimumMargin}
        currency={effectiveCurrency}
        disabled={disabled}
        label={t('inventory:pricing.salePrice')}
        showMarginInput={true}
        canEditBelowMinimum={canEditBelowMinimum}
        canEditAtLoss={canEditAtLoss}
      />
    </div>
  )
}

export default ProductPricingCard
