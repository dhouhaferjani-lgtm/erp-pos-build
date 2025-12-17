import { useState } from 'react'
import { useProfitLoss } from '../hooks/useProfitLoss'
import { QueryError } from '@/components/QueryError'
import type { ProfitLossLine } from '../types'

export function ProfitLossPage() {
  const today = new Date().toISOString().split('T')[0]
  const firstDayOfMonth = new Date(
    new Date().getFullYear(),
    new Date().getMonth(),
    1
  )
    .toISOString()
    .split('T')[0]

  const [dateFrom, setDateFrom] = useState<string>(firstDayOfMonth)
  const [dateTo, setDateTo] = useState<string>(today)

  const { data, isLoading, error, refetch } = useProfitLoss({
    date_from: dateFrom,
    date_to: dateTo,
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
        <h1 className="text-2xl font-bold">Profit & Loss Statement</h1>
        <button className="rounded bg-blue-600 px-4 py-2 text-white hover:bg-blue-700">
          Export
        </button>
      </div>

      {/* Filters */}
      <div className="mb-6 flex gap-4">
        <div>
          <label htmlFor="date-from" className="mb-2 block text-sm font-medium">
            From
          </label>
          <input
            id="date-from"
            type="date"
            value={dateFrom}
            onChange={(e) => setDateFrom(e.target.value)}
            className="rounded border border-gray-300 px-3 py-2"
          />
        </div>
        <div>
          <label htmlFor="date-to" className="mb-2 block text-sm font-medium">
            To
          </label>
          <input
            id="date-to"
            type="date"
            value={dateTo}
            onChange={(e) => setDateTo(e.target.value)}
            className="rounded border border-gray-300 px-3 py-2"
          />
        </div>
      </div>

      {/* Report */}
      {isLoading ? (
        <div>Loading...</div>
      ) : error ? (
        <QueryError
          error={error}
          onRetry={refetch}
          title="Failed to load profit & loss"
        />
      ) : (
        <div className="space-y-8">
          {/* Revenue Section */}
          <div>
            <h2 className="mb-4 text-xl font-bold">Revenue</h2>
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    Account Code
                  </th>
                  <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    Account Name
                  </th>
                  <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                    Amount
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {data?.revenue.map((line: ProfitLossLine) => (
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
                    Total Revenue
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                    {formatCurrency(data?.total_revenue || '0')}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          {/* Expenses Section */}
          <div>
            <h2 className="mb-4 text-xl font-bold">Expenses</h2>
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    Account Code
                  </th>
                  <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    Account Name
                  </th>
                  <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                    Amount
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {data?.expenses.map((line: ProfitLossLine) => (
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
                    Total Expenses
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                    {formatCurrency(data?.total_expenses || '0')}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          {/* Net Income */}
          <div className="border-t-2 border-gray-900 pt-4">
            <div className="flex justify-between text-xl font-bold">
              <span>Net Income</span>
              <span
                className={
                  parseFloat(data?.net_income || '0') >= 0
                    ? 'text-green-600'
                    : 'text-red-600'
                }
              >
                {formatCurrency(data?.net_income || '0')}
              </span>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
