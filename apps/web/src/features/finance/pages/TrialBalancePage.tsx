import { useState } from 'react'
import { useTrialBalance } from '../hooks/useTrialBalance'
import { QueryError } from '@/components/QueryError'
import type { TrialBalanceLine } from '../types'

export function TrialBalancePage() {
  const [asOfDate, setAsOfDate] = useState<string>(
    new Date().toISOString().split('T')[0]
  )

  const { data: trialBalanceData, isLoading, error, refetch } = useTrialBalance({
    as_of_date: asOfDate,
  })

  const lines = trialBalanceData?.lines || []
  const totalDebit = parseFloat(trialBalanceData?.total_debit || '0')
  const totalCredit = parseFloat(trialBalanceData?.total_credit || '0')

  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(amount)
  }

  return (
    <div className="p-6">
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold">Trial Balance</h1>
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

      {/* Table */}
      {isLoading ? (
        <div>Loading...</div>
      ) : error ? (
        <QueryError
          error={error}
          onRetry={refetch}
          title="Failed to load trial balance"
        />
      ) : (
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  Account Code
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  Account Name
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  Type
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  Debit
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  Credit
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {lines.map((line: TrialBalanceLine) => (
                <tr key={line.account_code}>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                    {line.account_code}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                    {line.account_name}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm capitalize text-gray-500">
                    {line.account_type}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                    {parseFloat(line.debit) > 0 ? formatCurrency(parseFloat(line.debit)) : ''}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                    {parseFloat(line.credit) > 0 ? formatCurrency(parseFloat(line.credit)) : ''}
                  </td>
                </tr>
              ))}
              {/* Totals Row */}
              <tr className="bg-gray-100 font-bold">
                <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900" colSpan={3}>
                  Total
                </td>
                <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                  {formatCurrency(totalDebit)}
                </td>
                <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                  {formatCurrency(totalCredit)}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
