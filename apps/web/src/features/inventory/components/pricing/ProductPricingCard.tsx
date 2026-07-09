import { DollarSign, TrendingUp, Shield, Calendar } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { MarginBadge } from './MarginIndicator'
import { useCurrency } from '@/hooks/useCurrency'
import { bccomp, bcdiv, bcmul, bcsub } from '@/lib/decimal'
import { colors, tokens, textColors, borderColors } from '@/lib/designTokens'
import { formatNumber, formatPercent } from '@/lib/format'

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
  const costPrice = product.cost_price ?? '0'
  const listPrice = product.list_price ?? '0'
  const targetMargin = product.target_margin_override ?? String(defaultTargetMargin)
  const minimumMargin = product.minimum_margin_override ?? String(defaultMinimumMargin)
  const lastPurchaseCost = product.last_purchase_cost ?? '0'

  // Calculate current margin if we have both cost and list price
  const currentMargin = bccomp(costPrice, '0') > 0 && bccomp(listPrice, '0') > 0
    ? bcmul(bcdiv(bcsub(listPrice, costPrice, 6), costPrice, 6), '100', 2)
    : '0'

  // Determine margin level
  const getMarginLevel = (): 'green' | 'yellow' | 'orange' | 'red' => {
    if (bccomp(currentMargin, '0') < 0) return 'red'
    if (bccomp(currentMargin, minimumMargin) < 0) return 'orange'
    if (bccomp(currentMargin, targetMargin) < 0) return 'yellow'
    return 'green'
  }

  const marginLevel = getMarginLevel()

  // Calculate suggested price based on target margin
  const suggestedPrice = bccomp(costPrice, '0') > 0 && bccomp(targetMargin, '100') < 0
    ? bcdiv(costPrice, bcsub('1', bcdiv(targetMargin, '100', 6), 6), decimals)
    : '0'

  const formatMoney = (amount: string): string => `$${formatNumber(amount, decimals, 'en-US')}`

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
              {formatMoney(costPrice)}
            </p>
            {bccomp(lastPurchaseCost, '0') > 0 && bccomp(lastPurchaseCost, costPrice) !== 0 && (
              <p className={`text-right text-xs tabular-nums ${textColors.tertiary}`}>
                {t('pricing.lastCost', { amount: formatMoney(lastPurchaseCost) })}
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
            {formatMoney(listPrice)}
          </p>
          {bccomp(currentMargin, '0') > 0 && (
            <p className={`text-right text-xs tabular-nums ${textColors.tertiary}`}>
              {t('pricing.marginValue', { value: formatPercent(currentMargin) })}
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
              {formatPercent(targetMargin)}
            </p>
            <p className={`text-right text-xs tabular-nums ${textColors.tertiary}`}>
              {t('pricing.minMargin', { margin: formatPercent(minimumMargin) })}
            </p>
          </div>
        )}

        {/* Suggested Price */}
        {canViewCosts && bccomp(suggestedPrice, '0') > 0 && (
          <div>
            <div className={`flex items-center gap-1 text-xs ${textColors.tertiary}`}>
              <TrendingUp className="h-3 w-3" />
              <span>{t('pricing.suggestedPrice')}</span>
            </div>
            <p className={`mt-1 text-right text-lg font-semibold tabular-nums ${textColors.brand}`}>
              {formatMoney(suggestedPrice)}
            </p>
            <p className={`text-right text-xs tabular-nums ${textColors.tertiary}`}>
              {t('pricing.atMargin', { margin: formatPercent(targetMargin) })}
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
      {canViewCosts && bccomp(costPrice, '0') > 0 && bccomp(listPrice, '0') > 0 && (
        <div className={`border-t ${borderColors.light} px-4 py-3`}>
          <div className="space-y-2 text-xs">
            <div className="flex justify-between">
              <span className={textColors.tertiary}>{t('pricing.markup')}:</span>
              <span className={`font-medium tabular-nums ${textColors.primary}`}>
                {formatMoney(bcsub(listPrice, costPrice, decimals))}
              </span>
            </div>
            <div className="flex justify-between">
              <span className={textColors.tertiary}>{t('pricing.marginPercent')}:</span>
              <span className={`font-semibold tabular-nums ${marginLevelTextColor}`}>
                {formatPercent(currentMargin)}
              </span>
            </div>
            {bccomp(currentMargin, targetMargin) < 0 && (
              <div className={`rounded-md p-2 ${tokens.alert.warning}`}>
                {t('pricing.belowTargetHint', { price: formatMoney(suggestedPrice) })}
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
