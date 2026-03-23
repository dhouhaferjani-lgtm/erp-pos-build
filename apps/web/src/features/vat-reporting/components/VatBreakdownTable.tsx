import { useTranslation } from 'react-i18next'
import type { VatRateBreakdown } from '../types'

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
      <table className="min-w-full divide-y divide-gray-200">
        <thead className="bg-gray-50">
          <tr>
            <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
              {t('finance:vatReporting.columns.rate')}
            </th>
            <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
              {t('finance:vatReporting.columns.baseAmount')}
            </th>
            <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
              {t('finance:vatReporting.columns.vatAmount')}
            </th>
            <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
              {t('finance:vatReporting.columns.documentCount')}
            </th>
            {showRecoverable && (
              <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('finance:vatReporting.columns.recoverable')}
              </th>
            )}
          </tr>
        </thead>
        <tbody className="divide-y divide-gray-200 bg-white">
          {breakdowns.map((breakdown) => (
            <tr key={breakdown.tax_rate}>
              <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                {breakdown.tax_rate}
              </td>
              <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                {formatAmount(breakdown.base_amount)}
              </td>
              <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                {formatAmount(breakdown.vat_amount)}
              </td>
              <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                {breakdown.document_count}
              </td>
              {showRecoverable && (
                <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                  {breakdown.is_recoverable
                    ? t('finance:vatReporting.columns.yes')
                    : t('finance:vatReporting.columns.no')}
                </td>
              )}
            </tr>
          ))}
          {/* Total row */}
          <tr className="bg-gray-100 font-bold">
            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
              {t('finance:vatReporting.total')}
            </td>
            <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
              {formatAmount(totalBase)}
            </td>
            <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
              {formatAmount(totalVat)}
            </td>
            <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
              {totalDocs}
            </td>
            {showRecoverable && (
              <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900" />
            )}
          </tr>
        </tbody>
      </table>
    </div>
  )
}
