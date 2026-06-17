import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useTrialBalance } from '../hooks/useTrialBalance'
import { QueryError } from '@/components/QueryError'
import { PageHeader } from '../../../components/molecules/PageHeader'
import { Button, FormField, Input } from '../../../components/atoms'
import { tokens, textColors, borderColors } from '../../../lib/designTokens'
import { cn } from '../../../lib/utils'
import type { TrialBalanceLine } from '../types'

export function TrialBalancePage() {
  const { t } = useTranslation(['finance'])
  const [asOfDate, setAsOfDate] = useState<string>(
    new Date().toISOString().split('T')[0]
  )

  const { data: trialBalanceData, isLoading, error, refetch } = useTrialBalance({
    as_of_date: asOfDate,
  })

  const lines = trialBalanceData?.lines ?? []
  const totalDebit = parseFloat(trialBalanceData?.total_debit ?? '0')
  const totalCredit = parseFloat(trialBalanceData?.total_credit ?? '0')

  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(amount)
  }

  const numericCell = cn('whitespace-nowrap px-6 py-4 text-end text-sm tabular-nums', textColors.primary)
  const numericHeader = cn(
    'px-6 py-3 text-end text-xs font-medium uppercase tracking-wider tabular-nums',
    textColors.tertiary
  )
  const labelHeader = cn(
    'px-6 py-3 text-start text-xs font-medium uppercase tracking-wider',
    textColors.tertiary
  )

  return (
    <div className="p-6">
      <PageHeader
        title={t('finance:reports.trialBalanceReport.title')}
        actions={
          <Button variant="secondary">
            {t('finance:reports.common.export')}
          </Button>
        }
      />

      {/* Filters */}
      <div className="mb-6 max-w-xs">
        <FormField
          label={t('finance:reports.common.asOfDate')}
          htmlFor="as-of-date"
        >
          <Input
            id="as-of-date"
            type="date"
            value={asOfDate}
            onChange={(e) => {
              setAsOfDate(e.target.value)
            }}
          />
        </FormField>
      </div>

      {/* Table */}
      {isLoading ? (
        <div className={textColors.tertiary}>
          {t('finance:reports.common.loading')}
        </div>
      ) : error ? (
        <QueryError
          error={error}
          onRetry={() => {
            void refetch()
          }}
          title={t('finance:reports.trialBalanceReport.loadError')}
        />
      ) : (
        <div className="overflow-x-auto">
          <table className={cn('min-w-full divide-y', borderColors.divideDefault)}>
            <thead className={tokens.table.header}>
              <tr>
                <th className={labelHeader}>
                  {t('finance:reports.common.accountCode')}
                </th>
                <th className={labelHeader}>
                  {t('finance:reports.common.accountName')}
                </th>
                <th className={labelHeader}>
                  {t('finance:reports.common.type')}
                </th>
                <th className={numericHeader}>
                  {t('finance:ledger.columns.debit')}
                </th>
                <th className={numericHeader}>
                  {t('finance:ledger.columns.credit')}
                </th>
              </tr>
            </thead>
            <tbody className={cn('divide-y bg-white', borderColors.divideDefault)}>
              {lines.map((line: TrialBalanceLine) => (
                <tr key={line.account_code}>
                  <td className={cn('whitespace-nowrap px-6 py-4 text-sm', textColors.primary)}>
                    {line.account_code}
                  </td>
                  <td className={cn('whitespace-nowrap px-6 py-4 text-sm', textColors.primary)}>
                    {line.account_name}
                  </td>
                  <td className={cn('whitespace-nowrap px-6 py-4 text-sm capitalize', textColors.tertiary)}>
                    {line.account_type}
                  </td>
                  <td className={numericCell}>
                    {parseFloat(line.debit) > 0 ? formatCurrency(parseFloat(line.debit)) : ''}
                  </td>
                  <td className={numericCell}>
                    {parseFloat(line.credit) > 0 ? formatCurrency(parseFloat(line.credit)) : ''}
                  </td>
                </tr>
              ))}
              {/* Totals Row */}
              <tr className={cn(tokens.table.header, 'font-bold')}>
                <td className={cn('whitespace-nowrap px-6 py-4 text-sm', textColors.primary)} colSpan={3}>
                  {t('finance:reports.common.total')}
                </td>
                <td className={numericCell}>
                  {formatCurrency(totalDebit)}
                </td>
                <td className={numericCell}>
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
