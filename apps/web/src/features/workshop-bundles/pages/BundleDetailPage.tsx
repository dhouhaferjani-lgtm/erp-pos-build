import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router-dom'
import { borderColors, textColors } from '../../../lib/designTokens'
import { useBundle } from '../hooks/useBundles'
import { BundleForm } from '../components/organisms/BundleForm'
import { BundleComponentRow } from '../components/molecules/BundleComponentRow'
import { VehicleApplicabilityChip } from '../components/atoms/VehicleApplicabilityChip'
import { BundleExpandedPreview } from '../components/organisms/BundleExpandedPreview'

export function BundleDetailPage() {
  const { t } = useTranslation('workshop-bundles')
  const { id } = useParams<{ id: string }>()
  const { data: bundle, isLoading, error } = useBundle(id)

  if (isLoading) {
    return <div className={`p-6 text-sm ${textColors.tertiary}`}>{t('detail.loading')}</div>
  }

  if (error !== null || bundle === undefined) {
    return <div className={`p-6 text-sm ${textColors.error}`}>{t('detail.error')}</div>
  }

  return (
    <div className="mx-auto max-w-5xl space-y-6 p-6">
      <h1 className={`text-2xl font-semibold ${textColors.primary}`}>
        {t('detail.title', { name: bundle.name })}
      </h1>

      <section className={`rounded-lg border ${borderColors.light} bg-white p-4`}>
        <h2 className={`mb-3 text-sm font-medium uppercase tracking-wide ${textColors.tertiary}`}>
          {t('detail.fields')}
        </h2>
        <BundleForm initial={bundle} defaultCurrency={bundle.currency} />
      </section>

      <section className={`rounded-lg border ${borderColors.light} bg-white p-4`}>
        <h2 className={`mb-3 text-sm font-medium uppercase tracking-wide ${textColors.tertiary}`}>
          {t('detail.components', { count: bundle.components.length })}
        </h2>
        {bundle.components.length === 0 ? (
          <p className={`text-sm ${textColors.tertiary}`}>{t('detail.emptyComponents')}</p>
        ) : (
          <div>
            {bundle.components.map((component) => (
              <BundleComponentRow
                key={component.id}
                component={component}
                currency={bundle.currency}
              />
            ))}
          </div>
        )}
      </section>

      <section className={`rounded-lg border ${borderColors.light} bg-white p-4`}>
        <h2 className={`mb-3 text-sm font-medium uppercase tracking-wide ${textColors.tertiary}`}>
          {t('detail.applicabilities')}
        </h2>
        {bundle.vehicle_applicabilities.length === 0 ? (
          <p className={`text-sm ${textColors.tertiary}`}>{t('detail.emptyApplicabilities')}</p>
        ) : (
          <div className="flex flex-wrap gap-1.5">
            {bundle.vehicle_applicabilities.map((a) => (
              <VehicleApplicabilityChip key={a.id} applicability={a} />
            ))}
          </div>
        )}
      </section>

      <section>
        <h2 className={`mb-3 text-sm font-medium uppercase tracking-wide ${textColors.tertiary}`}>
          {t('detail.expansionPreview')}
        </h2>
        <BundleExpandedPreview bundleId={bundle.id} currency={bundle.currency} />
      </section>
    </div>
  )
}
