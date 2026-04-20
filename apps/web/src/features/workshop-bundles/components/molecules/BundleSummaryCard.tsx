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
      className="flex w-full items-start justify-between gap-3 rounded-lg border border-gray-200 bg-white p-4 text-left transition-colors hover:border-blue-400 hover:bg-blue-50 disabled:cursor-default disabled:hover:border-gray-200 disabled:hover:bg-white"
    >
      <div className="flex-1">
        <div className="flex items-center gap-2">
          <span className="font-mono text-xs text-gray-500">{bundle.code}</span>
        </div>
        <h3 className="mt-1 text-sm font-medium text-gray-900">{bundle.name}</h3>
        {bundle.description !== null && (
          <p className="mt-1 line-clamp-2 text-xs text-gray-600">
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
