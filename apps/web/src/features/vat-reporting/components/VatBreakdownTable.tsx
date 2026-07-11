import { useTranslation } from 'react-i18next'
import type { VatRateBreakdown } from '../types'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

interface VatBreakdownTableProps {
  breakdowns: VatRateBreakdown[]
  showRecoverable?: boolean
}

function formatAmount(value: string | number): string {
  const num = typeof value === 'string' ? parseFloat(value) : value
  return new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(num)
}

export function VatBreakdownTable({ breakdowns, showRecoverable }: VatBreakdownTableProps) {
  const { t } = useTranslation('finance')

  const totalBase = breakdowns.reduce((sum, b) => sum + parseFloat(b.base_amount), 0)
  const totalVat = breakdowns.reduce((sum, b) => sum + parseFloat(b.vat_amount), 0)
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
                {formatAmount(breakdown.base_amount)}
              </td>
              <td className={`whitespace-nowrap px-6 py-4 text-end text-sm ${colorTokens.text.primary}`}>
                {formatAmount(breakdown.vat_amount)}
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
              {formatAmount(totalBase)}
            </td>
            <td className={`whitespace-nowrap px-6 py-4 text-end text-sm ${colorTokens.text.primary}`}>
              {formatAmount(totalVat)}
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
