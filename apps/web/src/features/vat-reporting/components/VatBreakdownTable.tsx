import { useTranslation } from 'react-i18next'
import type { VatRateBreakdown } from '../types'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'
import { useCurrency } from '@/hooks/useCurrency'
import { bcadd } from '@/lib/decimal'

interface VatBreakdownTableProps {
  breakdowns: VatRateBreakdown[]
  showRecoverable?: boolean
}

export function VatBreakdownTable({ breakdowns, showRecoverable }: VatBreakdownTableProps) {
  const { t } = useTranslation('finance')
  // m-6 (2026-08-06 gate): base_amount/vat_amount are canonical decimal
  // strings -- summed with bcadd (never parseFloat/+) and rendered through
  // the currency-aware formatter so no float ever touches these values.
  const { format } = useCurrency()

  const totalBase = breakdowns.reduce((sum, b) => bcadd(sum, b.base_amount), '0')
  const totalVat = breakdowns.reduce((sum, b) => bcadd(sum, b.vat_amount), '0')
  const totalDocs = breakdowns.reduce((sum, b) => sum + b.document_count, 0)

  return (
    <div className="overflow-x-auto">
      <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
        <thead className={`${colorTokens.surface.page}`}>
          <tr>
            <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
              {t('finance:vatReporting.columns.rate')}
            </th>
            <th className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
              {t('finance:vatReporting.columns.baseAmount')}
            </th>
            <th className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
              {t('finance:vatReporting.columns.vatAmount')}
            </th>
            <th className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
              {t('finance:vatReporting.columns.documentCount')}
            </th>
            {showRecoverable && (
              <th className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                {t('finance:vatReporting.columns.recoverable')}
              </th>
            )}
          </tr>
        </thead>
        <tbody className={`divide-y ${colorTokens.border.divider} bg-white`}>
          {breakdowns.map((breakdown) => (
            <tr key={breakdown.tax_rate}>
              <td className={`whitespace-nowrap px-6 py-4 text-sm ${colorTokens.text.primary}`}>
                {breakdown.tax_rate}
              </td>
              <td className={`whitespace-nowrap px-6 py-4 text-end text-sm ${colorTokens.text.primary}`}>
                {format(breakdown.base_amount, { symbol: false })}
              </td>
              <td className={`whitespace-nowrap px-6 py-4 text-end text-sm ${colorTokens.text.primary}`}>
                {format(breakdown.vat_amount, { symbol: false })}
              </td>
              <td className={`whitespace-nowrap px-6 py-4 text-end text-sm ${colorTokens.text.primary}`}>
                {breakdown.document_count}
              </td>
              {showRecoverable && (
                <td className={`whitespace-nowrap px-6 py-4 text-end text-sm ${colorTokens.text.primary}`}>
                  {breakdown.is_recoverable
                    ? t('finance:vatReporting.columns.yes')
                    : t('finance:vatReporting.columns.no')}
                </td>
              )}
            </tr>
          ))}
          {/* Total row */}
          <tr className={`${colorTokens.surface.muted} font-bold`}>
            <td className={`whitespace-nowrap px-6 py-4 text-sm ${colorTokens.text.primary}`}>
              {t('finance:vatReporting.total')}
            </td>
            <td className={`whitespace-nowrap px-6 py-4 text-end text-sm ${colorTokens.text.primary}`}>
              {format(totalBase, { symbol: false })}
            </td>
            <td className={`whitespace-nowrap px-6 py-4 text-end text-sm ${colorTokens.text.primary}`}>
              {format(totalVat, { symbol: false })}
            </td>
            <td className={`whitespace-nowrap px-6 py-4 text-end text-sm ${colorTokens.text.primary}`}>
              {totalDocs}
            </td>
            {showRecoverable && (
              <td className={`whitespace-nowrap px-6 py-4 text-sm ${colorTokens.text.primary}`} />
            )}
          </tr>
        </tbody>
      </DataTable>
    </div>
  )
}
