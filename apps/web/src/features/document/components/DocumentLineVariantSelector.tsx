import { useTranslation } from 'react-i18next'
import { useVariantsForProduct } from '../../catalog/hooks/useVariants'
import { textColors, tokens } from '@/lib/designTokens'

export interface DocumentLineVariantSelectorProps {
  productId: string
  /** Currently selected variant id, or null when the bare product is selected. */
  value: string | null
  /** Fired with the chosen variant id (or null when cleared). */
  onChange: (variantId: string | null) => void
  disabled?: boolean
}

/**
 * B2B document-line variant picker. Fetches the active variants for a product
 * and renders a `<select>` so the line can carry a specific variant identity.
 * Emits the chosen variant id (or null for the bare product). Pricing for the
 * chosen variant is resolved downstream by the document pricing pipeline
 * (variant price_override then product price); this component only owns the
 * selection.
 *
 * Renders nothing when the product has no variants, so non-variant lines are
 * unaffected.
 */
export function DocumentLineVariantSelector({
  productId,
  value,
  onChange,
  disabled = false,
}: DocumentLineVariantSelectorProps) {
  const { t } = useTranslation('documents')
  const { data: variants, isLoading } = useVariantsForProduct(productId)

  const active = (variants ?? []).filter((v) => v.is_active)

  if (!isLoading && active.length === 0) {
    return null
  }

  return (
    <label className="flex flex-col gap-1">
      <span className={`text-xs font-medium ${textColors.secondary}`}>
        {t('lines.variant', 'Variant')}
      </span>
      <select
        value={value ?? ''}
        disabled={disabled || isLoading}
        onChange={(e) => { onChange(e.target.value === '' ? null : e.target.value) }}
        className={tokens.input.base}
      >
        <option value="">
          {isLoading
            ? t('lines.variantLoading', 'Loading variants...')
            : t('lines.variantPlaceholder', 'Select a variant')}
        </option>
        {active.map((variant) => (
          <option key={variant.id} value={variant.id}>
            {variant.name_suffix.trim() || variant.variant_code} ({variant.sku})
          </option>
        ))}
      </select>
    </label>
  )
}
