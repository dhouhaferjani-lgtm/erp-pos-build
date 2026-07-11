import { useTranslation } from 'react-i18next'

import { FormField } from '@/components/atoms/FormField/FormField'
import { Input } from '@/components/atoms/Input/Input'
import { MoneyInput } from '@/components/atoms/MoneyInput/MoneyInput'
import { QuantityInput } from '@/components/atoms/QuantityInput/QuantityInput'
import { TaxConfigurationField } from '@/components/molecules/TaxConfigurationField'
import { textColors, tokens } from '@/lib/designTokens'
import { formatCurrency } from '@/lib/format'
import { cn } from '@/lib/utils'
import { EditorSectionCard } from '../editor/components/EditorSectionCard'
import { priceTtcFromHt, resolveMarginState } from './productPricingMath'
import type { ProductSectionsEditAdapter, ProductSectionsViewAdapter } from './types'

export type ProductPricingAdapter =
  | Pick<
      ProductSectionsViewAdapter,
      | 'mode'
      | 'canViewCostPrices'
      | 'costPrices'
      | 'currency'
      | 'locale'
      | 'moneyScale'
      | 'formatCurrency'
      | 'formatPercent'
      | 'product'
      | 'discountPolicyVerdict'
    >
  | (Pick<
      ProductSectionsEditAdapter,
      'mode' | 'canViewCostPrices' | 'currency' | 'locale' | 'moneyScale' | 'pricing'
    > & {
      isEditing: boolean
      product: { cost_price: string | null } | null
      form: Pick<ProductSectionsEditAdapter['form'], 'control' | 'setValue' | 'watch'>
    })

interface ProductPricingSectionProps {
  adapter: ProductPricingAdapter
}

function PricingMetric({ label, value, tone }: { label: string; value: string; tone?: string }) {
  return (
    <div>
      <div className={tokens.label.base}>{label}</div>
      <div className={cn('mt-1 text-base font-semibold tabular-nums', tone ?? textColors.primary)}>
        {value}
      </div>
    </div>
  )
}

export function ProductPricingSection({ adapter }: ProductPricingSectionProps) {
  const { t } = useTranslation(['catalog', 'inventory'])

  if (adapter.mode === 'view') {
    const salePriceHt = adapter.product.sale_price
    const salePriceTtc = salePriceHt === null
      ? null
      : priceTtcFromHt(salePriceHt, adapter.product.tax_rate, adapter.moneyScale)
    const marginState = resolveMarginState(
      adapter.costPrices?.costPrice,
      salePriceHt,
      adapter.costPrices?.effectiveMargins?.target_margin,
      adapter.costPrices?.effectiveMargins?.minimum_margin,
    )
    const marginTone = marginState.level === 'success'
      ? textColors.success
      : marginState.level === 'warning'
        ? textColors.warningDark
        : marginState.level === 'danger'
          ? textColors.error
          : textColors.tertiary

    return (
      <EditorSectionCard
        id="section-pricing"
        title={t('catalog:editor.sectionLabels.pricing')}
      >
        <PricingMetric label={t('inventory:pricing.salePriceHt')} value={adapter.formatCurrency(salePriceHt)} />
        <PricingMetric label={t('inventory:pricing.salePriceTtc')} value={adapter.formatCurrency(salePriceTtc)} />
        <PricingMetric
          label={t('inventory:products.fields.taxRate')}
          value={adapter.formatPercent(adapter.product.tax_rate)}
        />
        <PricingMetric
          label={t('inventory:pricing.maxDiscount')}
          value={adapter.formatPercent(adapter.product.max_discount_percent)}
        />

        {adapter.canViewCostPrices && adapter.costPrices !== null && (
          <>
            <PricingMetric label={t('inventory:pricing.wac')} value={adapter.formatCurrency(adapter.costPrices.costPrice)} />
            <PricingMetric
              label={t('inventory:pricing.lastPurchasePrice')}
              value={adapter.formatCurrency(adapter.costPrices.lastPurchaseCost)}
            />
            <PricingMetric
              label={t('inventory:pricing.margin')}
              value={adapter.formatPercent(marginState.marginPercent === '' ? null : marginState.marginPercent)}
              tone={marginTone}
            />
            {adapter.discountPolicyVerdict?.floorPriceNet !== null && adapter.discountPolicyVerdict?.floorPriceNet !== undefined && (
              <PricingMetric
                label={t('inventory:pricing.floorPrice')}
                value={adapter.formatCurrency(adapter.discountPolicyVerdict.floorPriceNet)}
              />
            )}
          </>
        )}
      </EditorSectionCard>
    )
  }

  const { pricing } = adapter
  const taxConfigurationId = adapter.form.watch('tax_configuration_id')

  return (
    <EditorSectionCard
      id="section-pricing"
      title={t('catalog:editor.sectionLabels.pricing')}
    >
      {adapter.canViewCostPrices && (
        <FormField
          label={t('inventory:products.purchasePrice')}
          htmlFor="purchase_price"
          helperText={t('inventory:products.purchasePriceHelper')}
        >
          <MoneyInput
            id="purchase_price"
            currency={adapter.currency}
            value={pricing.cost}
            onChange={pricing.commitCost}
          />
        </FormField>
      )}

      {adapter.canViewCostPrices && adapter.isEditing && adapter.product !== null && (
        <FormField
          label={t('inventory:products.costWac')}
          htmlFor="cost_wac"
          helperText={t('inventory:products.costWacHelper')}
        >
          <Input
            id="cost_wac"
            aria-label={t('inventory:products.costWac')}
            type="text"
            value={adapter.product.cost_price === null
              ? ''
              : formatCurrency(adapter.product.cost_price, {
                  currency: adapter.currency,
                  locale: adapter.locale,
                })}
            readOnly
            className={cn(textColors.tertiary, 'cursor-not-allowed')}
          />
        </FormField>
      )}

      {adapter.canViewCostPrices && (
        <FormField label={t('inventory:products.marginPercent')} htmlFor="margin_percent">
          <QuantityInput
            id="margin_percent"
            aria-label={t('inventory:products.marginPercent')}
            decimalPlaces={2}
            value={pricing.margin.value}
            onChange={pricing.margin.onChange}
            onFocus={pricing.margin.onFocus}
            onBlur={pricing.margin.onBlur}
          />
        </FormField>
      )}

      <FormField label={t('inventory:products.priceHt')} htmlFor="sale_price_ht">
        <MoneyInput
          id="sale_price_ht"
          aria-label={t('inventory:products.priceHt')}
          currency={adapter.currency}
          value={pricing.priceHt.value}
          onChange={pricing.priceHt.onChange}
          onFocus={pricing.priceHt.onFocus}
          onBlur={pricing.priceHt.onBlur}
        />
      </FormField>

      <FormField label={t('inventory:products.priceTtc')} htmlFor="sale_price_ttc">
        <MoneyInput
          id="sale_price_ttc"
          aria-label={t('inventory:products.priceTtc')}
          currency={adapter.currency}
          value={pricing.priceTtc.value}
          onChange={pricing.priceTtc.onChange}
          onFocus={pricing.priceTtc.onFocus}
          onBlur={pricing.priceTtc.onBlur}
        />
      </FormField>

      <div className="sm:col-span-2">
        <TaxConfigurationField
          label={t('inventory:products.fields.taxRate')}
          value={taxConfigurationId}
          onChange={(configurationId, taxRate) => {
            adapter.form.setValue('tax_configuration_id', configurationId)
            adapter.form.setValue('tax_rate', taxRate)
          }}
        />
      </div>
    </EditorSectionCard>
  )
}
