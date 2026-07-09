import { AlertTriangle, BadgePercent, ShieldCheck, TrendingUp } from 'lucide-react'
import type { ReactNode } from 'react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Button } from '@/components/atoms'
import { usePermissions } from '@/hooks/usePermissions'
import { formatCurrency, formatPercent } from '@/lib/format'
import { bccomp } from '@/lib/decimal'
import { cn } from '@/lib/utils'
import { borderColors, colors, textColors, tokens } from '@/lib/designTokens'

import { priceTtcFromHt, resolveMarginState } from './pricingMath'
import { PricingModeCalculator } from './PricingModeCalculator'

export interface PricingProduct {
  id: string
  name: string
  sku: string
  sale_price: string | null
  cost_price: string | null
  last_purchase_cost?: string | null
  tax_rate: string | null
  max_discount_percent?: string | null
  effective_margins?: {
    target_margin: string
    minimum_margin: string
    source: string
  } | null
}

export interface DiscountPolicyVerdict {
  allowed: boolean
  blocksSale: boolean
  severity: 'info' | 'warn' | 'block'
  requiresPermission: string | null
  maxDiscountPercent: string
  discountPercent: string
  floorPriceNet: string | null
  floorBasis: string
  floorEnforcement: string
  mode: string
  overridable: boolean
  requiresReason: boolean
  policyVersion: string
  policyAsOf: string
  reasons: string[]
  meta: Record<string, unknown>
}

interface PricingIntelligencePanelProps {
  product: PricingProduct
  currency: string
  locale: string
  moneyScale?: number
  verdict?: DiscountPolicyVerdict | undefined
  onSalePriceHtChange?: ((value: string) => void) | undefined
}

function formatAmount(amount: string | null | undefined, currency: string, locale: string): string {
  if (amount === null || amount === undefined || amount.trim() === '') return '-'
  return formatCurrency(amount, { currency, locale })
}

function metric(label: string, value: string, icon?: ReactNode) {
  return (
    <div>
      <div className={cn('flex items-center gap-1.5 text-xs font-medium uppercase', textColors.tertiary)}>
        {icon}
        {label}
      </div>
      <div className={cn('mt-1 text-base font-semibold tabular-nums', textColors.primary)}>{value}</div>
    </div>
  )
}

export function PricingIntelligencePanel({
  product,
  currency,
  locale,
  moneyScale = 3,
  verdict,
  onSalePriceHtChange,
}: PricingIntelligencePanelProps) {
  const { t } = useTranslation('inventory')
  const { hasPermission } = usePermissions()
  const canViewCostPrices = hasPermission('pricing.view_cost_prices')
  const [basis, setBasis] = useState<'HT' | 'TTC'>('HT')
  const targetMargin = product.effective_margins?.target_margin ?? null
  const minimumMargin = product.effective_margins?.minimum_margin ?? null
  const marginState = resolveMarginState(product.cost_price, product.sale_price, targetMargin, minimumMargin)
  // Prefer the backend-resolved rate (tax config → fallback) so TTC matches the floor's basis (R3-6).
  // meta is an index-signature record — access with brackets (noPropertyAccessFromIndexSignature).
  const metaTaxRate = verdict?.meta['resolvedTaxRate']
  const resolvedTaxRate = (typeof metaTaxRate === 'string' ? metaTaxRate : null) ?? product.tax_rate
  const salePriceTtc = product.sale_price !== null
    ? priceTtcFromHt(product.sale_price, resolvedTaxRate, moneyScale)
    : null
  const salePriceValue = basis === 'HT' ? product.sale_price : salePriceTtc
  const marginTone = marginState.level === 'success'
    ? textColors.success
    : marginState.level === 'warning'
      ? textColors.warningDark
      : marginState.level === 'danger'
        ? textColors.error
        : textColors.tertiary
  const maxDiscount = verdict?.maxDiscountPercent ?? product.max_discount_percent
  const verdictTone = verdict === undefined
    ? ''
    : verdict.severity === 'block' || verdict.blocksSale || (verdict.floorPriceNet !== null && bccomp(product.sale_price ?? '0', verdict.floorPriceNet) < 0)
      ? cn('border', borderColors.error, tokens.alert.error)
      : verdict.severity === 'warn'
        ? cn('border', borderColors.warning, tokens.alert.warning)
        : cn('border', borderColors.primary, tokens.alert.info)

  return (
    <div className={cn('rounded-lg border bg-white', borderColors.light)}>
      <div className={cn('flex flex-wrap items-center justify-between gap-3 border-b px-6 py-4', borderColors.light)}>
        <h2 className={cn('text-base font-semibold', textColors.primary)}>{t('products.sections.pricing')}</h2>
        <div className={cn('inline-flex rounded-md border p-0.5', borderColors.light, colors.neutral[50])}>
          {(['HT', 'TTC'] as const).map((option) => (
            <Button
              key={option}
              type="button"
              variant={basis === option ? 'primary' : 'ghost'}
              size="sm"
              onClick={() => { setBasis(option) }}
              className="h-7 px-2.5 text-xs"
            >
              {option}
            </Button>
          ))}
        </div>
      </div>
      <div className="space-y-4 px-6 py-4">
        <div className="grid gap-4 sm:grid-cols-3">
          {metric(
            basis === 'HT' ? t('pricing.salePriceHt') : t('pricing.salePriceTtc'),
            formatAmount(salePriceValue, currency, locale),
            <BadgePercent className="h-3.5 w-3.5" />,
          )}
          <div>
            <div className={cn('text-xs font-medium uppercase', textColors.tertiary)}>
              {t('products.fields.taxRate')}
            </div>
            <div className={cn('mt-1 text-base font-semibold tabular-nums', textColors.primary)}>
              {resolvedTaxRate !== null && resolvedTaxRate.trim() !== '' ? formatPercent(resolvedTaxRate) : '-'}
            </div>
          </div>
          {maxDiscount !== null && maxDiscount !== undefined && (
            metric(t('pricing.maxDiscount'), formatPercent(maxDiscount), <ShieldCheck className="h-3.5 w-3.5" />)
          )}
        </div>

        {canViewCostPrices && (
          <>
            <div className="grid gap-4 sm:grid-cols-3">
              {metric(t('pricing.wac'), formatAmount(product.cost_price, currency, locale))}
              {metric(t('pricing.lastPurchasePrice'), formatAmount(product.last_purchase_cost, currency, locale))}
              <div>
                <div className={cn('flex items-center gap-1.5 text-xs font-medium uppercase', textColors.tertiary)}>
                  <TrendingUp className="h-3.5 w-3.5" />
                  {t('pricing.margin')}
                </div>
                <div className={cn('mt-1 text-base font-semibold tabular-nums', marginTone)}>
                  {marginState.marginPercent === '' ? '-' : formatPercent(marginState.marginPercent)}
                </div>
                {(targetMargin !== null || minimumMargin !== null) && (
                  <div className={cn('mt-1 text-xs', textColors.tertiary)}>
                    {t('pricing.marginThresholds', {
                      target: targetMargin === null ? '-' : formatPercent(targetMargin),
                      minimum: minimumMargin === null ? '-' : formatPercent(minimumMargin),
                    })}
                  </div>
                )}
              </div>
            </div>

            {verdict !== undefined && (
              <div className={cn('rounded-md p-3 text-sm', verdictTone)}>
                <div className="flex items-start gap-2">
                  <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                  <div className="space-y-1">
                    <div className="font-medium">{t('pricing.discountPolicy')}</div>
                    {verdict.floorPriceNet !== null && (
                      <div>
                        {t('pricing.floorPrice')}: {formatAmount(verdict.floorPriceNet, currency, locale)}
                      </div>
                    )}
                    {verdict.requiresPermission !== null && (
                      <div>{verdict.requiresPermission}</div>
                    )}
                  </div>
                </div>
              </div>
            )}

            <PricingModeCalculator
              costPrice={product.cost_price}
              salePriceHt={product.sale_price}
              moneyScale={moneyScale}
              onSalePriceHtChange={onSalePriceHtChange}
            />
          </>
        )}
      </div>
    </div>
  )
}
