import { useState } from 'react'
import { useAgedReceivables } from '../hooks/useAgedReceivables'
import { QueryError } from '@/components/QueryError'
import type { AgedReceivablesLine } from '../types'

export function AgedReceivablesPage() {
  const [asOfDate, setAsOfDate] = useState<string>(
    new Date().toISOString().split('T')[0]
  )

  const { data: lines = [], isLoading, error, refetch } = useAgedReceivables({
    as_of_date: asOfDate,
  })

  const formatCurrency = (amount: string | number) => {
    const num = typeof amount === 'string' ? parseFloat(amount) : amount
    return new Intl.NumberFormat('en-US', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(num)
  }

  const totals = lines.reduce(
    (acc, line) => ({
      current: acc.current + parseFloat(line.current || '0'),
      days_30: acc.days_30 + parseFloat(line.days_30 || '0'),
      days_60: acc.days_60 + parseFloat(line.days_60 || '0'),
      days_90: acc.days_90 + parseFloat(line.days_90 || '0'),
      over_90: acc.over_90 + parseFloat(line.over_90 || '0'),
      total: acc.total + parseFloat(line.total || '0'),
    }),
    {
      current: 0,
      days_30: 0,
      days_60: 0,
      days_90: 0,
      over_90: 0,
      total: 0,
    }
  )

  return (
    <div className="p-6">
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold">Aged Receivables Report</h1>
        <button className="rounded bg-blue-600 px-4 py-2 text-white hover:bg-blue-700">
          Export
        </button>
      </div>

      {/* Filters */}
      <div className="mb-6">
        <label htmlFor="as-of-date" className="mb-2 block text-sm font-medium">
          As of Date
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
        <div>Loading...</div>
      ) : error ? (
        <QueryError
          error={error}
          onRetry={refetch}
          title="Failed to load aged receivables"
        />
      ) : (
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  Customer
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  Current
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  1-30 Days
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  31-60 Days
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  61-90 Days
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  Over 90 Days
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  Total
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {lines.map((line: AgedReceivablesLine) => (
                <tr key={line.customer_id}>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                    {line.customer_name}
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
                <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">Total</td>
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
