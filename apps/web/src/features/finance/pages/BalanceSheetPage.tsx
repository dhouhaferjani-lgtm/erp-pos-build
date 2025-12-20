import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useBalanceSheet } from '../hooks/useBalanceSheet'
import { QueryError } from '@/components/QueryError'
import type { BalanceSheetLine } from '../types'

export function BalanceSheetPage() {
  const { t } = useTranslation(['finance'])
  const [asOfDate, setAsOfDate] = useState<string>(
    new Date().toISOString().split('T')[0]
  )

  const { data, isLoading, error, refetch } = useBalanceSheet({
    as_of_date: asOfDate,
  })

  const formatCurrency = (amount: string | number) => {
    const num = typeof amount === 'string' ? parseFloat(amount) : amount
    return new Intl.NumberFormat('en-US', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(num)
  }

  return (
    <div className="p-6">
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold">{t('finance:reports.balanceSheetReport.title')}</h1>
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
      ) : error ? (
        <QueryError
          error={error}
          onRetry={refetch}
          title={t('finance:reports.balanceSheetReport.loadError')}
        />
      ) : (
        <div className="grid grid-cols-1 gap-8 lg:grid-cols-2">
          {/* Left Column: Assets */}
          <div>
            <h2 className="mb-4 text-xl font-bold">{t('finance:reports.balanceSheetReport.assets')}</h2>
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('finance:reports.common.accountCode')}
                  </th>
                  <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('finance:reports.common.accountName')}
                  </th>
                  <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('finance:reports.common.amount')}
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {data?.assets.map((line: BalanceSheetLine) => (
                  <tr key={line.account_code}>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                      {line.account_code}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                      {line.account_name}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                      {formatCurrency(line.amount)}
                    </td>
                  </tr>
                ))}
                <tr className="bg-gray-100 font-bold">
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900" colSpan={2}>
                    {t('finance:reports.balanceSheetReport.totalAssets')}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                    {formatCurrency(data?.total_assets || '0')}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          {/* Right Column: Liabilities & Equity */}
          <div className="space-y-8">
            {/* Liabilities Section */}
            <div>
              <h2 className="mb-4 text-xl font-bold">{t('finance:reports.balanceSheetReport.liabilities')}</h2>
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                      {t('finance:reports.common.accountCode')}
                    </th>
                    <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                      {t('finance:reports.common.accountName')}
                    </th>
                    <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                      {t('finance:reports.common.amount')}
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {data?.liabilities.map((line: BalanceSheetLine) => (
                    <tr key={line.account_code}>
                      <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                        {line.account_code}
                      </td>
                      <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                        {line.account_name}
                      </td>
                      <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                        {formatCurrency(line.amount)}
                      </td>
                    </tr>
                  ))}
                  <tr className="bg-gray-100 font-bold">
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900" colSpan={2}>
                      {t('finance:reports.balanceSheetReport.totalLiabilities')}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                      {formatCurrency(data?.total_liabilities || '0')}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            {/* Equity Section */}
            <div>
              <h2 className="mb-4 text-xl font-bold">{t('finance:reports.balanceSheetReport.equity')}</h2>
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                      {t('finance:reports.common.accountCode')}
                    </th>
                    <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                      {t('finance:reports.common.accountName')}
                    </th>
                    <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                      {t('finance:reports.common.amount')}
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {data?.equity.map((line: BalanceSheetLine) => (
                    <tr key={line.account_code}>
                      <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                        {line.account_code}
                      </td>
                      <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                        {line.account_name}
                      </td>
                      <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                        {formatCurrency(line.amount)}
                      </td>
                    </tr>
                  ))}
                  <tr className="bg-gray-100 font-bold">
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900" colSpan={2}>
                      {t('finance:reports.balanceSheetReport.totalEquity')}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                      {formatCurrency(data?.total_equity || '0')}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
