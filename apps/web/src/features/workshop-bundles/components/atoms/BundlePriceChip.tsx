import { useTranslation } from 'react-i18next'
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
      <span className="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800">
        {price} {currency}
      </span>
    )
  }

  return (
    <span className="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600">
      {t('priceChip.sumOfLines')}
    </span>
  )
}
