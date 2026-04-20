import { useTranslation } from 'react-i18next'
import { borderColors, colors, textColors } from '../../../../lib/designTokens'
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
    return <div className={`p-4 text-sm ${textColors.tertiary}`}>{t('expansion.loading')}</div>
  }

  if (error !== null || lines === undefined) {
    return (
      <div className={`p-4 text-sm ${textColors.error}`}>{t('expansion.error')}</div>
    )
  }

  if (lines.length === 0) {
    return <div className={`p-4 text-sm ${textColors.tertiary}`}>{t('expansion.empty')}</div>
  }

  const priced = lines.filter((l) => !l.is_from_fixed_bundle)
  const informational = lines.filter((l) => l.is_from_fixed_bundle)

  return (
    <div className={`${borderColors.divideDefault} divide-y rounded-lg border ${borderColors.light} bg-white`}>
      <div className={`px-4 py-2 ${colors.neutral[50]} text-xs font-medium uppercase tracking-wide ${textColors.tertiary}`}>
        {t('expansion.pricedLines')}
      </div>
      {priced.map((line) => (
        <div
          key={`${line.component_type}-${line.component_id ?? 'header'}`}
          className="flex items-center justify-between px-4 py-2"
        >
          <div className="flex items-center gap-2">
            <ComponentTypeIcon type={line.component_type} className={`h-4 w-4 ${textColors.tertiary}`} />
            <div>
              <div className={`text-sm font-medium ${textColors.primary}`}>{line.display_name}</div>
              <div className={`text-xs ${textColors.tertiary}`}>
                {line.quantity} {line.unit} × {line.unit_price} {currency}
              </div>
            </div>
          </div>
          <span className={`font-mono text-sm ${textColors.primary}`}>
            {line.line_total} {currency}
          </span>
        </div>
      ))}
      {informational.length > 0 && (
        <>
          <div className={`px-4 py-2 ${colors.neutral[50]} text-xs font-medium uppercase tracking-wide ${textColors.tertiary}`}>
            {t('expansion.informationalLines')}
          </div>
          {informational.map((line) => (
            <div
              key={`info-${line.component_type}-${line.component_id ?? 'header'}`}
              className={`flex items-center justify-between px-4 py-2 ${textColors.tertiary}`}
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
