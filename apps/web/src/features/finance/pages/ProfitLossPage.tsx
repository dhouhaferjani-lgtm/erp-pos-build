import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useProfitLoss } from '../hooks/useProfitLoss'
import { QueryError } from '@/components/QueryError'
import { PageHeader } from '../../../components/molecules/PageHeader'
import { Button, FormField, Input } from '../../../components/atoms'
import { tokens, textColors, borderColors } from '../../../lib/designTokens'
import { cn } from '../../../lib/utils'
import type { ProfitLossLine } from '../types'

export function ProfitLossPage() {
  const { t } = useTranslation(['finance'])
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

  const thLabel = cn(
    'px-6 py-3 text-start text-xs font-medium uppercase tracking-wider',
    textColors.tertiary
  )
  const thAmount = cn(
    'px-6 py-3 text-end text-xs font-medium uppercase tracking-wider tabular-nums',
    textColors.tertiary
  )
  const tdLabel = cn(
    'whitespace-nowrap px-6 py-4 text-sm',
    textColors.primary
  )
  const tdAmount = cn(
    'whitespace-nowrap px-6 py-4 text-end text-sm tabular-nums',
    textColors.primary
  )

  const renderSection = (
    heading: string,
    lines: ProfitLossLine[] | undefined,
    totalLabel: string,
    totalValue: string
  ) => (
    <div>
      <h2 className={cn('mb-4 text-xl font-bold', textColors.primary)}>
        {heading}
      </h2>
      <table
        className={cn('min-w-full divide-y', borderColors.divideDefault)}
      >
        <thead className={tokens.table.header}>
          <tr>
            <th className={thLabel}>
              {t('finance:reports.common.accountCode')}
            </th>
            <th className={thLabel}>
              {t('finance:reports.common.accountName')}
            </th>
            <th className={thAmount}>
              {t('finance:reports.common.amount')}
            </th>
          </tr>
        </thead>
        <tbody
          className={cn('divide-y bg-white', borderColors.divideDefault)}
        >
          {lines?.map((line) => (
            <tr key={line.account_code}>
              <td className={tdLabel}>{line.account_code}</td>
              <td className={tdLabel}>{line.account_name}</td>
              <td className={tdAmount}>{formatCurrency(line.amount)}</td>
            </tr>
          ))}
          <tr className={cn(tokens.table.header, 'font-bold')}>
            <td className={tdLabel} colSpan={2}>
              {totalLabel}
            </td>
            <td className={tdAmount}>{formatCurrency(totalValue)}</td>
          </tr>
        </tbody>
      </table>
    </div>
  )

  return (
    <div className="p-6">
      <PageHeader
        title={t('finance:reports.profitLossReport.title')}
        actions={
          <Button variant="primary">
            {t('finance:reports.common.export')}
          </Button>
        }
      />

      {/* Filters */}
      <div className="mb-6 flex gap-4">
        <FormField
          label={t('finance:reports.common.from')}
          htmlFor="date-from"
        >
          <Input
            id="date-from"
            type="date"
            value={dateFrom}
            onChange={(e) => {
              setDateFrom(e.target.value)
            }}
          />
        </FormField>
        <FormField label={t('finance:reports.common.to')} htmlFor="date-to">
          <Input
            id="date-to"
            type="date"
            value={dateTo}
            onChange={(e) => {
              setDateTo(e.target.value)
            }}
          />
        </FormField>
      </div>

      {/* Report */}
      {isLoading ? (
        <div className={textColors.tertiary}>
          {t('finance:reports.common.loading')}
        </div>
      ) : error ? (
        <QueryError
          error={error}
          onRetry={refetch}
          title={t('finance:reports.profitLossReport.loadError')}
        />
      ) : (
        <div className="space-y-8">
          {renderSection(
            t('finance:reports.profitLossReport.revenue'),
            data?.revenue,
            t('finance:reports.profitLossReport.totalRevenue'),
            data?.total_revenue ?? '0'
          )}

          {renderSection(
            t('finance:reports.profitLossReport.expenses'),
            data?.expenses,
            t('finance:reports.profitLossReport.totalExpenses'),
            data?.total_expenses ?? '0'
          )}

          {/* Net Income */}
          <div className={cn('border-t-2 pt-4', borderColors.dark)}>
            <div className="flex justify-between text-xl font-bold">
              <span>{t('finance:reports.profitLossReport.netIncome')}</span>
              <span
                className={cn(
                  'tabular-nums',
                  parseFloat(data?.net_income ?? '0') >= 0
                    ? textColors.success
                    : textColors.error
                )}
              >
                {formatCurrency(data?.net_income ?? '0')}
              </span>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
