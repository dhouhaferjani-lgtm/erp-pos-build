import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Plus } from 'lucide-react'
import { borderColors, textColors, tokens } from '../../../../lib/designTokens'
import { useBundles } from '../../hooks/useBundles'
import { BundlePriceChip } from '../atoms/BundlePriceChip'
import { ServiceIntervalBadge } from '../atoms/ServiceIntervalBadge'
import { VehicleApplicabilityChip } from '../atoms/VehicleApplicabilityChip'

export function BundleList() {
  const { t } = useTranslation('workshop-bundles')
  const { data, isLoading, error } = useBundles()

  if (isLoading) {
    return <div className={`p-6 text-sm ${textColors.tertiary}`}>{t('list.loading')}</div>
  }

  if (error !== null || data === undefined) {
    return <div className={`p-6 text-sm ${textColors.error}`}>{t('list.error')}</div>
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className={`text-2xl font-semibold ${textColors.primary}`}>{t('list.title')}</h1>
        <Link
          to="/workshop/bundles/new"
          className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.sm} gap-1.5`}
        >
          <Plus className="h-4 w-4" />
          {t('list.createCta')}
        </Link>
      </div>

      {data.data.length === 0 && (
        <div className={`rounded-lg border ${borderColors.light} bg-white p-6 text-sm ${textColors.tertiary}`}>
          {t('list.empty')}
        </div>
      )}

      <div className="space-y-2">
        {data.data.map((bundle) => (
          <Link
            key={bundle.id}
            to={`/workshop/bundles/${bundle.id}`}
            className={`block rounded-lg border ${borderColors.light} bg-white p-4 transition-colors ${tokens.card.hoverPrimary}`}
          >
            <div className="flex items-start justify-between gap-3">
              <div className="flex-1">
                <div className="flex items-center gap-2">
                  <span className={`font-mono text-xs ${textColors.tertiary}`}>{bundle.code}</span>
                  {!bundle.is_active && (
                    <span className={`${tokens.badge.base} ${tokens.badge.gray}`}>
                      {t('list.inactive')}
                    </span>
                  )}
                </div>
                <h2 className={`mt-1 text-base font-medium ${textColors.primary}`}>{bundle.name}</h2>
                {bundle.description !== null && (
                  <p className={`mt-1 line-clamp-2 text-sm ${textColors.tertiary}`}>
                    {bundle.description}
                  </p>
                )}
                <div className="mt-2 flex flex-wrap items-center gap-1.5">
                  <ServiceIntervalBadge
                    km={bundle.service_interval_km}
                    months={bundle.service_interval_months}
                  />
                  {bundle.vehicle_applicabilities.map((a) => (
                    <VehicleApplicabilityChip key={a.id} applicability={a} />
                  ))}
                </div>
              </div>
              <BundlePriceChip
                price={bundle.base_price}
                currency={bundle.currency}
                pricingMode={bundle.pricing_mode}
              />
            </div>
          </Link>
        ))}
      </div>
    </div>
  )
}
