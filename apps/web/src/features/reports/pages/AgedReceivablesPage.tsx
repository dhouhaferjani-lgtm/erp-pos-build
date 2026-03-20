import { useState } from 'react'
import { usePageTitle } from '@/hooks/usePageTitle'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { fetchAgedReceivables } from '../api/reportsApi'
import { formatCurrency } from '@/lib/format'
import { useCompanyStore } from '@/stores/companyStore'

/**
 * Aged Receivables Report Page
 *
 * Shows outstanding invoices grouped by aging buckets:
 * - Current (not yet due)
 * - 1-30 days overdue
 * - 31-60 days overdue
 * - 61-90 days overdue
 * - 90+ days overdue
 */
export function AgedReceivablesPage() {
  const { t } = useTranslation(['reports', 'common'])
  usePageTitle('agedReceivables.title', 'reports')
  const currentCompany = useCompanyStore((state) => state.getCurrentCompany())
  const [asOfDate, setAsOfDate] = useState<string>(new Date().toISOString().split('T')[0])

  const { data: report, isLoading, error } = useQuery({
    queryKey: ['aged-receivables', asOfDate],
    queryFn: () => fetchAgedReceivables({ as_of_date: asOfDate }),
  })

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="text-gray-600">{t('common:loading')}</div>
      </div>
    )
  }

  if (error) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="text-red-600">{t('common:error')}</div>
      </div>
    )
  }

  if (!report) {
    return null
  }

  const currency = currentCompany?.currency ?? 'EUR'

  return (
    <div className="container mx-auto px-4 py-8">
      {/* Header */}
      <div className="mb-8">
        <h1 className="text-3xl font-bold text-gray-900 mb-2">
          {t('reports:agedReceivables.title')}
        </h1>
        <p className="text-gray-600">{t('reports:agedReceivables.description')}</p>
      </div>

      {/* Date Filter */}
      <div className="mb-6 bg-white rounded-lg shadow p-4">
        <label className="block text-sm font-medium text-gray-700 mb-2">
          {t('reports:agedReceivables.asOfDate')}
        </label>
        <input
          type="date"
          value={asOfDate}
          onChange={(e) => { setAsOfDate(e.target.value); }}
          className="border border-gray-300 rounded-md px-3 py-2"
        />
      </div>

      {/* Summary Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
        <div className="bg-white rounded-lg shadow p-4">
          <div className="text-sm font-medium text-gray-600 mb-1">
            {t('reports:agedReceivables.totalOutstanding')}
          </div>
          <div className="text-2xl font-bold text-gray-900">
            {formatCurrency(parseFloat(report.total_outstanding), { currency })}
          </div>
        </div>

        <div className="bg-green-50 rounded-lg shadow p-4">
          <div className="text-sm font-medium text-green-700 mb-1">
            {t('reports:agedReceivables.current')}
          </div>
          <div className="text-2xl font-bold text-green-900">
            {formatCurrency(parseFloat(report.summary.current), { currency })}
          </div>
        </div>

        <div className="bg-yellow-50 rounded-lg shadow p-4">
          <div className="text-sm font-medium text-yellow-700 mb-1">
            {t('reports:agedReceivables.days1To30')}
          </div>
          <div className="text-2xl font-bold text-yellow-900">
            {formatCurrency(parseFloat(report.summary.days_1_30), { currency })}
          </div>
        </div>

        <div className="bg-orange-50 rounded-lg shadow p-4">
          <div className="text-sm font-medium text-orange-700 mb-1">
            {t('reports:agedReceivables.days31To60')}
          </div>
          <div className="text-2xl font-bold text-orange-900">
            {formatCurrency(parseFloat(report.summary.days_31_60), { currency })}
          </div>
        </div>

        <div className="bg-red-50 rounded-lg shadow p-4">
          <div className="text-sm font-medium text-red-700 mb-1">
            {t('reports:agedReceivables.daysOver90')}
          </div>
          <div className="text-2xl font-bold text-red-900">
            {formatCurrency(parseFloat(report.summary.days_over_90), { currency })}
          </div>
        </div>
      </div>

      {/* Partner Breakdown */}
      <div className="bg-white rounded-lg shadow overflow-hidden">
        <div className="px-6 py-4 border-b border-gray-200">
          <h2 className="text-lg font-semibold text-gray-900">
            {t('reports:agedReceivables.byPartner')}
          </h2>
        </div>

        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('reports:agedReceivables.partner')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('reports:agedReceivables.total')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('reports:agedReceivables.current')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium text-gray-500 uppercase tracking-wider">
                  1-30
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium text-gray-500 uppercase tracking-wider">
                  31-60
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium text-gray-500 uppercase tracking-wider">
                  61-90
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium text-gray-500 uppercase tracking-wider">
                  90+
                </th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {report.by_partner.map((partner) => (
                <tr key={partner.partner_id} className="hover:bg-gray-50">
                  <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                    {partner.partner_name}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-end font-semibold text-gray-900">
                    {formatCurrency(parseFloat(partner.total_outstanding), { currency })}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-end text-green-600">
                    {formatCurrency(parseFloat(partner.current), { currency })}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-end text-yellow-600">
                    {formatCurrency(parseFloat(partner.days_1_30), { currency })}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-end text-orange-600">
                    {formatCurrency(parseFloat(partner.days_31_60), { currency })}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-end text-red-500">
                    {formatCurrency(parseFloat(partner.days_61_90), { currency })}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-end text-red-700 font-semibold">
                    {formatCurrency(parseFloat(partner.days_over_90), { currency })}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}
