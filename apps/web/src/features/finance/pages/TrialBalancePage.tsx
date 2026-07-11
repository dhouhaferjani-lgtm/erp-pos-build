import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useTrialBalance } from '../hooks/useTrialBalance'
import { QueryError } from '@/components/QueryError'
import { PageHeader } from '../../../components/molecules/PageHeader'
import { Button, FormField, Input } from '../../../components/atoms'
import { tokens, textColors, borderColors } from '../../../lib/designTokens'
import { cn } from '../../../lib/utils'
import { bccomp } from '../../../lib/decimal'
import { useCompany } from '../../../hooks/useCompany'
import {
  formatReportCurrency,
  getTodayDateInputValue,
} from './reportPageUtils'
import type { TrialBalanceLine } from '../types'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

export function TrialBalancePage() {
  const { t } = useTranslation(['finance'])
  const { currentCompany } = useCompany()
  const [asOfDate, setAsOfDate] = useState<string>(() => getTodayDateInputValue())

  const { data: trialBalanceData, isLoading, error, refetch } = useTrialBalance({
    as_of_date: asOfDate,
  })

  const lines = trialBalanceData?.lines ?? []
  const totalDebit = trialBalanceData?.total_debit ?? '0'
  const totalCredit = trialBalanceData?.total_credit ?? '0'
  const formatMoney = (amount: string) => formatReportCurrency(amount, currentCompany)

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
          <DataTable className={cn('min-w-full divide-y', borderColors.divideDefault)}>
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
                    {bccomp(line.debit, '0') > 0 ? formatMoney(line.debit) : ''}
                  </td>
                  <td className={numericCell}>
                    {bccomp(line.credit, '0') > 0 ? formatMoney(line.credit) : ''}
                  </td>
                </tr>
              ))}
              {/* Totals Row */}
              <tr className={cn(tokens.table.header, 'font-bold')}>
                <td className={cn('whitespace-nowrap px-6 py-4 text-sm', textColors.primary)} colSpan={3}>
                  {t('finance:reports.common.total')}
                </td>
                <td className={numericCell}>
                  {formatMoney(totalDebit)}
                </td>
                <td className={numericCell}>
                  {formatMoney(totalCredit)}
                </td>
              </tr>
            </tbody>
          </DataTable>
        </div>
      )}
    </div>
  )
}
