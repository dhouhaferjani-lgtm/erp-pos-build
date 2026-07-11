import { useTranslation } from 'react-i18next'
import { useCompany } from '../../../hooks/useCompany'
import { formatPercent } from '../../../lib/format'
import { PriceInputWithMargin } from '../../molecules/PriceInputWithMargin'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

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
    <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-4 shadow-sm ${colorTokens.variants.darkBorderGray700} ${colorTokens.variants.darkBgGray800}`}>
      {/* Header */}
      <div className="mb-4">
        <h3 className={`text-lg font-semibold ${colorTokens.text.primary} ${colorTokens.variants.darkTextGray100}`}>
          {productName}
        </h3>
        {sku && (
          <p className={`text-sm ${colorTokens.text.subtle} ${colorTokens.variants.darkTextGray400}`}>
            {t('inventory:products.sku')}: {sku}
          </p>
        )}
      </div>

      {/* Cost Information */}
      <div className={`mb-4 grid grid-cols-2 gap-4 rounded-lg ${colorTokens.surface.page} p-3 ${colorTokens.variants.darkBgGray700Alpha50}`}>
        <div>
          <span className={`text-xs ${colorTokens.text.subtle} ${colorTokens.variants.darkTextGray400}`}>
            {t('inventory:pricing.wac')}
          </span>
          <p className={`text-lg font-semibold ${colorTokens.text.primary} ${colorTokens.variants.darkTextGray100}`}>
            {formatCurrency(weightedAverageCost)}
          </p>
        </div>
        <div>
          <span className={`text-xs ${colorTokens.text.subtle} ${colorTokens.variants.darkTextGray400}`}>
            {t('inventory:pricing.lastPurchasePrice')}
          </span>
          <p className={`text-lg font-semibold ${colorTokens.text.primary} ${colorTokens.variants.darkTextGray100}`}>
            {formatCurrency(lastPurchasePrice)}
          </p>
        </div>
      </div>

      {/* Margin Settings */}
      <div className="mb-4 flex items-center gap-4 text-sm">
        <div className="flex items-center gap-2">
          <span className={`${colorTokens.text.subtle} ${colorTokens.variants.darkTextGray400}`}>
            {t('inventory:pricing.targetMargin')}:
          </span>
          <span className={`font-medium ${colorTokens.text.primary} ${colorTokens.variants.darkTextGray100}`}>
            {formatPercent(targetMargin)}
          </span>
        </div>
        <div className="flex items-center gap-2">
          <span className={`${colorTokens.text.subtle} ${colorTokens.variants.darkTextGray400}`}>
            {t('inventory:pricing.minimumMargin')}:
          </span>
          <span className={`font-medium ${colorTokens.text.primary} ${colorTokens.variants.darkTextGray100}`}>
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
