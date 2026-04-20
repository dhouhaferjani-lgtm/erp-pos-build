import { borderColors, textColors, tokens } from '../../../../lib/designTokens'
import type { ApplicableBundleData } from '../../types'
import { BundlePriceChip } from '../atoms/BundlePriceChip'
import { ServiceIntervalBadge } from '../atoms/ServiceIntervalBadge'

interface BundleSummaryCardProps {
  bundle: ApplicableBundleData
  onSelect?: (bundle: ApplicableBundleData) => void
}

export function BundleSummaryCard({
  bundle,
  onSelect,
}: BundleSummaryCardProps) {
  const clickable = onSelect !== undefined

  return (
    <button
      type="button"
      disabled={!clickable}
      onClick={() => onSelect?.(bundle)}
      className={`flex w-full items-start justify-between gap-3 rounded-lg border ${borderColors.light} bg-white p-4 text-left transition-colors ${tokens.card.hoverPrimary} disabled:cursor-default`}
    >
      <div className="flex-1">
        <div className="flex items-center gap-2">
          <span className={`font-mono text-xs ${textColors.tertiary}`}>{bundle.code}</span>
        </div>
        <h3 className={`mt-1 text-sm font-medium ${textColors.primary}`}>{bundle.name}</h3>
        {bundle.description !== null && (
          <p className={`mt-1 line-clamp-2 text-xs ${textColors.tertiary}`}>
            {bundle.description}
          </p>
        )}
        <div className="mt-2 flex flex-wrap items-center gap-1.5">
          <ServiceIntervalBadge
            km={bundle.service_interval_km}
            months={bundle.service_interval_months}
          />
        </div>
      </div>
      <BundlePriceChip
        price={bundle.base_price}
        currency={bundle.currency}
        pricingMode={bundle.pricing_mode}
      />
    </button>
  )
}
