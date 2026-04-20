import { useTranslation } from 'react-i18next'
import { useBundleExpansion } from '../../hooks/useBundles'
import { ComponentTypeIcon } from '../atoms/ComponentTypeIcon'

interface BundleExpandedPreviewProps {
  bundleId: string
  quantity?: string
  vehicleId?: string | undefined
  currency: string
}

export function BundleExpandedPreview({
  bundleId,
  quantity = '1',
  vehicleId,
  currency,
}: BundleExpandedPreviewProps) {
  const { t } = useTranslation('workshop-bundles')
  const { data: lines, isLoading, error } = useBundleExpansion(bundleId, quantity, vehicleId)

  if (isLoading) {
    return <div className="p-4 text-sm text-gray-500">{t('expansion.loading')}</div>
  }

  if (error !== null || lines === undefined) {
    return (
      <div className="p-4 text-sm text-red-600">{t('expansion.error')}</div>
    )
  }

  if (lines.length === 0) {
    return <div className="p-4 text-sm text-gray-500">{t('expansion.empty')}</div>
  }

  const priced = lines.filter((l) => !l.is_from_fixed_bundle)
  const informational = lines.filter((l) => l.is_from_fixed_bundle)

  return (
    <div className="divide-y divide-gray-200 rounded-lg border border-gray-200 bg-white">
      <div className="px-4 py-2 bg-gray-50 text-xs font-medium uppercase tracking-wide text-gray-600">
        {t('expansion.pricedLines')}
      </div>
      {priced.map((line) => (
        <div
          key={`${line.component_type}-${line.component_id ?? 'header'}`}
          className="flex items-center justify-between px-4 py-2"
        >
          <div className="flex items-center gap-2">
            <ComponentTypeIcon type={line.component_type} className="h-4 w-4 text-gray-500" />
            <div>
              <div className="text-sm font-medium text-gray-900">{line.display_name}</div>
              <div className="text-xs text-gray-500">
                {line.quantity} {line.unit} × {line.unit_price} {currency}
              </div>
            </div>
          </div>
          <span className="font-mono text-sm text-gray-900">
            {line.line_total} {currency}
          </span>
        </div>
      ))}
      {informational.length > 0 && (
        <>
          <div className="px-4 py-2 bg-gray-50 text-xs font-medium uppercase tracking-wide text-gray-600">
            {t('expansion.informationalLines')}
          </div>
          {informational.map((line) => (
            <div
              key={`info-${line.component_type}-${line.component_id ?? 'header'}`}
              className="flex items-center justify-between px-4 py-2 text-gray-600"
            >
              <div className="flex items-center gap-2">
                <ComponentTypeIcon type={line.component_type} className="h-4 w-4" />
                <div className="text-sm">{line.display_name}</div>
              </div>
              <span className="font-mono text-xs">
                {line.quantity} {line.unit}
              </span>
            </div>
          ))}
        </>
      )}
    </div>
  )
}
