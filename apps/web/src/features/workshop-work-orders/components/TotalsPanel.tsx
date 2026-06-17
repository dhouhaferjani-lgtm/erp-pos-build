import { useTranslation } from 'react-i18next'
import { borderColors, textColors } from '@/lib/designTokens'
import type { WorkOrderTotals } from '../types'

interface TotalsPanelProps {
  estimated: WorkOrderTotals | null
  actual: WorkOrderTotals | null
  currency: string
}

/**
 * Totals rollup panel. When both `estimated` and `actual` are null, the panel
 * renders a redaction note (caller lacks `work-orders.view_financials`).
 */
export function TotalsPanel({ estimated, actual, currency }: TotalsPanelProps) {
  const { t } = useTranslation('workshop-work-orders')

  if (estimated === null && actual === null) {
    return (
      <div className={`rounded-lg border bg-white p-4 text-sm ${borderColors.light} ${textColors.tertiary}`}>
        {t('totals.redacted')}
      </div>
    )
  }

  return (
    <div className={`rounded-lg border bg-white p-4 ${borderColors.light}`}>
      <div className={`mb-2 text-sm font-semibold ${textColors.primary}`}>{t('totals.title')}</div>
      <div className="grid grid-cols-2 gap-x-6 gap-y-1 text-sm">
        {estimated !== null && (
          <TotalsColumn label={t('totals.estimated')} totals={estimated} currency={currency} />
        )}
        {actual !== null && (
          <TotalsColumn label={t('totals.actual')} totals={actual} currency={currency} />
        )}
      </div>
    </div>
  )
}

interface TotalsColumnProps {
  label: string
  totals: WorkOrderTotals
  currency: string
}

function TotalsColumn({ label, totals, currency }: TotalsColumnProps) {
  const { t } = useTranslation('workshop-work-orders')
  return (
    <div>
      <div className={`mb-1 text-xs uppercase tracking-wide ${textColors.tertiary}`}>{label}</div>
      <div className="space-y-0.5 font-mono text-xs">
        <Row label={t('totals.parts')} value={totals.parts_total} currency={currency} />
        <Row label={t('totals.labor')} value={totals.labor_total} currency={currency} />
        <Row label={t('totals.other')} value={totals.other_total} currency={currency} />
        <Row label={t('totals.tax')} value={totals.tax_total} currency={currency} />
        <div className={`border-t ${borderColors.light} pt-0.5`}>
          <Row label={t('totals.grand')} value={totals.grand_total} currency={currency} bold />
        </div>
      </div>
    </div>
  )
}

function Row({
  label,
  value,
  currency,
  bold = false,
}: {
  label: string
  value: string
  currency: string
  bold?: boolean
}) {
  return (
    <div className={`flex justify-between ${bold ? `font-semibold ${textColors.primary}` : ''}`}>
      <span>{label}</span>
      <span className="tabular-nums">
        {value} {currency}
      </span>
    </div>
  )
}
