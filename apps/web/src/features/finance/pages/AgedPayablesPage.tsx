import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAgedPayables } from '../hooks/useAgedPayables'
import type { AgedPayablesLine } from '../types'

export function AgedPayablesPage() {
  const { t } = useTranslation(['finance'])
  const [asOfDate, setAsOfDate] = useState<string>(
    new Date().toISOString().split('T')[0]
  )

  const { data: payablesData, isLoading } = useAgedPayables({
    as_of_date: asOfDate,
  })

  const lines = payablesData?.lines || []

  const formatCurrency = (amount: string | number) => {
    const num = typeof amount === 'string' ? parseFloat(amount) : amount
    return new Intl.NumberFormat('en-US', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(num)
  }

  const totals = {
    current: parseFloat(payablesData?.total_current || '0'),
    days_30: parseFloat(payablesData?.total_days_30 || '0'),
    days_60: parseFloat(payablesData?.total_days_60 || '0'),
    days_90: parseFloat(payablesData?.total_days_90 || '0'),
    over_90: parseFloat(payablesData?.total_over_90 || '0'),
    total: parseFloat(payablesData?.grand_total || '0'),
  }

  return (
    <div className="p-6">
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold">{t('finance:reports.agedPayablesReport.title')}</h1>
        <button className="rounded bg-blue-600 px-4 py-2 text-white hover:bg-blue-700">
          {t('finance:reports.common.export')}
        </button>
      </div>

      {/* Filters */}
      <div className="mb-6">
        <label htmlFor="as-of-date" className="mb-2 block text-sm font-medium">
          {t('finance:reports.common.asOfDate')}
        </label>
        <input
          id="as-of-date"
          type="date"
          value={asOfDate}
          onChange={(e) => { setAsOfDate(e.target.value); }}
          className="rounded border border-gray-300 px-3 py-2"
        />
      </div>

      {/* Report */}
      {isLoading ? (
        <div>{t('finance:reports.common.loading')}</div>
      ) : (
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('finance:reports.agedPayablesReport.vendor')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('finance:reports.agedPayablesReport.current')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('finance:reports.agedPayablesReport.days30')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('finance:reports.agedPayablesReport.days60')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('finance:reports.agedPayablesReport.days90')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('finance:reports.agedPayablesReport.over90')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('finance:reports.common.total')}
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {lines.map((line: AgedPayablesLine) => (
                <tr key={line.vendor_id}>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                    {line.vendor_name}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                    {formatCurrency(line.current)}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                    {formatCurrency(line.days_30)}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                    {formatCurrency(line.days_60)}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                    {formatCurrency(line.days_90)}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                    {formatCurrency(line.over_90)}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm font-medium text-gray-900">
                    {formatCurrency(line.total)}
                  </td>
                </tr>
              ))}
              {/* Totals Row */}
              <tr className="bg-gray-100 font-bold">
                <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">{t('finance:reports.common.total')}</td>
                <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                  {formatCurrency(totals.current)}
                </td>
                <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                  {formatCurrency(totals.days_30)}
                </td>
                <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                  {formatCurrency(totals.days_60)}
                </td>
                <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                  {formatCurrency(totals.days_90)}
                </td>
                <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                  {formatCurrency(totals.over_90)}
                </td>
                <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                  {formatCurrency(totals.total)}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
