import { useTranslation } from 'react-i18next'
import { tokens } from '../../../../lib/designTokens'
import type { BundlePricingMode } from '../../types'

interface BundlePriceChipProps {
  price: string | null
  currency: string
  pricingMode: BundlePricingMode
}

/**
 * Price chip: shows the authoritative price for a bundle.
 * In `fixed_bundle` mode the flat base_price is shown; in `standard`
 * mode a "— (sum of lines)" placeholder is shown since the real total
 * is only known at expansion time.
 */
export function BundlePriceChip({
  price,
  currency,
  pricingMode,
}: BundlePriceChipProps) {
  const { t } = useTranslation('workshop-bundles')

  if (pricingMode === 'fixed_bundle' && price !== null) {
    return (
      <span className={`${tokens.badge.base} ${tokens.badge.blue}`}>
        {price} {currency}
      </span>
    )
  }

  return (
    <span className={`${tokens.badge.base} ${tokens.badge.gray}`}>
      {t('priceChip.sumOfLines')}
    </span>
  )
}
