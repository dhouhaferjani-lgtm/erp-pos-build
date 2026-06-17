import { DollarSign, TrendingUp, Shield, Calendar } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { MarginBadge } from './MarginIndicator'
import { useCurrency } from '@/hooks/useCurrency'
import { colors, tokens, textColors, borderColors } from '@/lib/designTokens'

interface Product {
  id: string
  name: string
  sku: string
  cost_price?: string
  list_price?: string
  target_margin_override?: string
  minimum_margin_override?: string
  last_purchase_cost?: string
  cost_updated_at?: string
}

interface ProductPricingCardProps {
  product: Product
  defaultTargetMargin?: number
  defaultMinimumMargin?: number
  canViewCosts?: boolean
}

export function ProductPricingCard({
  product,
  defaultTargetMargin = 30,
  defaultMinimumMargin = 15,
  canViewCosts = true,
}: ProductPricingCardProps) {
  const { t } = useTranslation('inventory')
  const { decimals } = useCurrency()
  const costPrice = parseFloat(product.cost_price || '0')
  const listPrice = parseFloat(product.list_price || '0')
  const targetMargin = parseFloat(product.target_margin_override || String(defaultTargetMargin))
  const minimumMargin = parseFloat(product.minimum_margin_override || String(defaultMinimumMargin))
  const lastPurchaseCost = parseFloat(product.last_purchase_cost || '0')

  // Calculate current margin if we have both cost and list price
  const currentMargin = costPrice > 0 && listPrice > 0
    ? ((listPrice - costPrice) / costPrice) * 100
    : 0

  // Determine margin level
  const getMarginLevel = (): 'green' | 'yellow' | 'orange' | 'red' => {
    if (currentMargin < 0) return 'red'
    if (currentMargin < minimumMargin) return 'orange'
    if (currentMargin < targetMargin) return 'yellow'
    return 'green'
  }

  const marginLevel = getMarginLevel()

  // Calculate suggested price based on target margin
  const suggestedPrice = costPrice > 0
    ? costPrice / (1 - targetMargin / 100)
    : 0

  const formatDate = (dateString?: string) => {
    if (!dateString) return t('pricing.neverUpdated')
    const date = new Date(dateString)
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
  }

  // orange ("very low") shares the warning token with yellow ("low").
  const marginLevelTextColor =
    marginLevel === 'green' ? textColors.success :
    marginLevel === 'yellow' ? textColors.warningDark :
    marginLevel === 'orange' ? textColors.warningDark :
    textColors.error

  return (
    <div className={`overflow-hidden ${tokens.card.base} p-0`}>
      {/* Header */}
      <div className={`border-b ${borderColors.light} ${colors.neutral[50]} px-4 py-3`}>
        <div className="flex items-center justify-between">
          <div>
            <h3 className={`text-sm font-semibold ${textColors.primary}`}>{product.name}</h3>
            <p className={`text-xs ${textColors.tertiary}`}>{product.sku}</p>
          </div>
          <MarginBadge level={marginLevel} />
        </div>
      </div>

      {/* Pricing Grid */}
      <div className="grid grid-cols-2 gap-4 p-4">
        {/* Cost Price */}
        {canViewCosts && (
          <div>
            <div className={`flex items-center gap-1 text-xs ${textColors.tertiary}`}>
              <Shield className="h-3 w-3" />
              <span>{t('pricing.costPrice')}</span>
            </div>
            <p className={`mt-1 text-right text-lg font-semibold tabular-nums ${textColors.primary}`}>
              ${costPrice.toFixed(decimals)}
            </p>
            {lastPurchaseCost > 0 && lastPurchaseCost !== costPrice && (
              <p className={`text-right text-xs tabular-nums ${textColors.tertiary}`}>
                {t('pricing.lastCost', { amount: `$${lastPurchaseCost.toFixed(decimals)}` })}
              </p>
            )}
          </div>
        )}

        {/* List Price */}
        <div>
          <div className={`flex items-center gap-1 text-xs ${textColors.tertiary}`}>
            <DollarSign className="h-3 w-3" />
            <span>{t('pricing.listPrice')}</span>
          </div>
          <p className={`mt-1 text-right text-lg font-semibold tabular-nums ${textColors.primary}`}>
            ${listPrice.toFixed(decimals)}
          </p>
          {currentMargin > 0 && (
            <p className={`text-right text-xs tabular-nums ${textColors.tertiary}`}>
              {t('pricing.marginValue', { value: currentMargin.toFixed(1) })}
            </p>
          )}
        </div>

        {/* Target Margin */}
        {canViewCosts && (
          <div>
            <div className={`flex items-center gap-1 text-xs ${textColors.tertiary}`}>
              <TrendingUp className="h-3 w-3" />
              <span>{t('pricing.targetMargin')}</span>
            </div>
            <p className={`mt-1 text-right text-lg font-semibold tabular-nums ${textColors.primary}`}>
              {targetMargin.toFixed(1)}%
            </p>
            <p className={`text-right text-xs tabular-nums ${textColors.tertiary}`}>
              {t('pricing.minMargin', { margin: minimumMargin.toFixed(1) })}
            </p>
          </div>
        )}

        {/* Suggested Price */}
        {canViewCosts && suggestedPrice > 0 && (
          <div>
            <div className={`flex items-center gap-1 text-xs ${textColors.tertiary}`}>
              <TrendingUp className="h-3 w-3" />
              <span>{t('pricing.suggestedPrice')}</span>
            </div>
            <p className={`mt-1 text-right text-lg font-semibold tabular-nums ${textColors.brand}`}>
              ${suggestedPrice.toFixed(decimals)}
            </p>
            <p className={`text-right text-xs tabular-nums ${textColors.tertiary}`}>
              {t('pricing.atMargin', { margin: targetMargin.toFixed(1) })}
            </p>
          </div>
        )}
      </div>

      {/* Footer */}
      {canViewCosts && product.cost_updated_at && (
        <div className={`border-t ${borderColors.light} ${colors.neutral[50]} px-4 py-2`}>
          <div className={`flex items-center gap-1 text-xs ${textColors.tertiary}`}>
            <Calendar className="h-3 w-3" />
            <span>{t('pricing.costLastUpdated', { date: formatDate(product.cost_updated_at) })}</span>
          </div>
        </div>
      )}

      {/* Margin Details */}
      {canViewCosts && costPrice > 0 && listPrice > 0 && (
        <div className={`border-t ${borderColors.light} px-4 py-3`}>
          <div className="space-y-2 text-xs">
            <div className="flex justify-between">
              <span className={textColors.tertiary}>{t('pricing.markup')}:</span>
              <span className={`font-medium tabular-nums ${textColors.primary}`}>
                ${(listPrice - costPrice).toFixed(decimals)}
              </span>
            </div>
            <div className="flex justify-between">
              <span className={textColors.tertiary}>{t('pricing.marginPercent')}:</span>
              <span className={`font-semibold tabular-nums ${marginLevelTextColor}`}>
                {currentMargin.toFixed(2)}%
              </span>
            </div>
            {currentMargin < targetMargin && (
              <div className={`rounded-md p-2 ${tokens.alert.warning}`}>
                {t('pricing.belowTargetHint', { price: `$${suggestedPrice.toFixed(decimals)}` })}
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
