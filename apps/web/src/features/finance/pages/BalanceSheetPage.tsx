import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useBalanceSheet } from '../hooks/useBalanceSheet'
import { QueryError } from '@/components/QueryError'
import { PageHeader } from '../../../components/molecules/PageHeader'
import { Button, FormField, Input } from '../../../components/atoms'
import { tokens, textColors, borderColors } from '../../../lib/designTokens'
import { cn } from '../../../lib/utils'
import { useCompany } from '../../../hooks/useCompany'
import {
  formatReportCurrency,
  getTodayDateInputValue,
} from './reportPageUtils'
import type { BalanceSheetLine } from '../types'

export function BalanceSheetPage() {
  const { t } = useTranslation(['finance'])
  const { currentCompany } = useCompany()
  const [asOfDate, setAsOfDate] = useState<string>(() => getTodayDateInputValue())

  const { data, isLoading, error, refetch } = useBalanceSheet({
    as_of_date: asOfDate,
  })

  const formatMoney = (amount: string) => formatReportCurrency(amount, currentCompany)

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
    lines: BalanceSheetLine[] | undefined,
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
              <td className={tdAmount}>{formatMoney(line.amount)}</td>
            </tr>
          ))}
          <tr className={cn(tokens.table.header, 'font-bold')}>
            <td className={tdLabel} colSpan={2}>
              {totalLabel}
            </td>
            <td className={tdAmount}>{formatMoney(totalValue)}</td>
          </tr>
        </tbody>
      </table>
    </div>
  )

  return (
    <div className="p-6">
      <PageHeader
        title={t('finance:reports.balanceSheetReport.title')}
        actions={
          <Button variant="primary">
            {t('finance:reports.common.export')}
          </Button>
        }
      />

      {/* Filters */}
      <div className="mb-6">
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

      {/* Report */}
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
          title={t('finance:reports.balanceSheetReport.loadError')}
        />
      ) : (
        <div className="grid grid-cols-1 gap-8 lg:grid-cols-2">
          {/* Left Column: Assets */}
          {renderSection(
            t('finance:reports.balanceSheetReport.assets'),
            data?.assets,
            t('finance:reports.balanceSheetReport.totalAssets'),
            data?.total_assets ?? '0'
          )}

          {/* Right Column: Liabilities & Equity */}
          <div className="space-y-8">
            {renderSection(
              t('finance:reports.balanceSheetReport.liabilities'),
              data?.liabilities,
              t('finance:reports.balanceSheetReport.totalLiabilities'),
              data?.total_liabilities ?? '0'
            )}

            {renderSection(
              t('finance:reports.balanceSheetReport.equity'),
              data?.equity,
              t('finance:reports.balanceSheetReport.totalEquity'),
              data?.total_equity ?? '0'
            )}
          </div>
        </div>
      )}
    </div>
  )
}
