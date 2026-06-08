import { useTranslation } from 'react-i18next'
import { useVariantsForProduct } from '../hooks/useVariants'
import { textColors, borderColors, tokens } from '@/lib/designTokens'

export interface ProductDetailVariantPickerProps {
  productId: string
  /** Currently selected variant id, or null when none is chosen yet. */
  value: string | null
  onChange: (variantId: string) => void
}

/**
 * B2C catalog product-detail variant picker. Renders the product's active
 * variants as a radio group of pills so a shopper can pick a specific variant
 * (size / colour) before adding to cart. Emits the chosen variant id.
 *
 * Renders nothing when the product has no variants.
 */
export function ProductDetailVariantPicker({
  productId,
  value,
  onChange,
}: ProductDetailVariantPickerProps) {
  const { t } = useTranslation('catalog')
  const { data: variants, isLoading } = useVariantsForProduct(productId)

  const active = (variants ?? []).filter((v) => v.is_active)

  if (isLoading) {
    return (
      <p className={`text-sm ${textColors.tertiary}`}>
        {t('variants.loading', 'Loading variants...')}
      </p>
    )
  }

  if (active.length === 0) {
    return null
  }

  return (
    <div
      role="radiogroup"
      aria-label={t('variants.chooseVariant', 'Choose a variant')}
      className="flex flex-col gap-2"
    >
      <span className={`text-sm font-medium ${textColors.secondary}`}>
        {t('variants.chooseVariant', 'Choose a variant')}
      </span>
      <div className="flex flex-wrap gap-2">
        {active.map((variant) => {
          const isSelected = variant.id === value
          return (
            <button
              key={variant.id}
              type="button"
              role="radio"
              aria-checked={isSelected}
              onClick={() => { onChange(variant.id) }}
              className={[
                'rounded-full border-2 px-4 py-2 text-sm font-medium transition-colors',
                isSelected
                  ? `${borderColors.primary} ${tokens.button.primary}`
                  : `${borderColors.default} ${textColors.secondary} ${borderColors.hover}`,
              ].join(' ')}
            >
              {variant.name_suffix.trim() || variant.variant_code}
            </button>
          )
        })}
      </div>
    </div>
  )
}
