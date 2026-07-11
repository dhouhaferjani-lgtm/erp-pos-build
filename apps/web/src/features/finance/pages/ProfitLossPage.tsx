import { useState } from 'react'
import type { EChartsOption } from 'echarts'
import { useTranslation } from 'react-i18next'
import { useProfitLoss } from '../hooks/useProfitLoss'
import { QueryError } from '@/components/QueryError'
import { OwnerChart } from '@/features/owner-dashboard/components/OwnerChart'
import { PageHeader } from '../../../components/molecules/PageHeader'
import { Button } from '../../../components/atoms/Button/Button'
import { FormField } from '../../../components/atoms/FormField/FormField'
import { Input } from '../../../components/atoms/Input/Input'
import { StatCard } from '../../../components/ui/StatCard'
import { tokens, textColors, borderColors, chartColors } from '../../../lib/designTokens'
import { cn } from '../../../lib/utils'
import { bccomp } from '../../../lib/decimal'
import { useCompany } from '../../../hooks/useCompany'
import {
  formatReportCurrency,
  getCurrentMonthStartInputValue,
  getTodayDateInputValue,
} from './reportPageUtils'
import type { ProfitLossLine } from '../types'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

export function ProfitLossPage() {
  const { t } = useTranslation(['finance'])
  const { currentCompany } = useCompany()

  const [dateFrom, setDateFrom] = useState<string>(() => getCurrentMonthStartInputValue())
  const [dateTo, setDateTo] = useState<string>(() => getTodayDateInputValue())

  const { data, isLoading, error, refetch } = useProfitLoss({
    date_from: dateFrom,
    date_to: dateTo,
  })

  const formatMoney = (amount: string) => formatReportCurrency(amount, currentCompany)

  const totalRevenue = data?.total_revenue ?? '0'
  const totalExpenses = data?.total_expenses ?? '0'
  const netIncome = data?.net_income ?? '0'

  const revenueVsExpensesOption: EChartsOption = {
    color: [chartColors.primary, chartColors.warning],
    grid: { left: '3%', right: '4%', bottom: '3%', containLabel: true },
    tooltip: { trigger: 'axis' as const },
    xAxis: {
      type: 'category' as const,
      data: [
        t('finance:reports.profitLossReport.totalRevenue'),
        t('finance:reports.profitLossReport.totalExpenses'),
      ],
    },
    yAxis: { type: 'value' as const },
    series: [
      {
        type: 'bar' as const,
        data: [totalRevenue, totalExpenses],
      },
    ],
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
      <DataTable
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
      </DataTable>
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
          onRetry={() => {
            void refetch()
          }}
          title={t('finance:reports.profitLossReport.loadError')}
        />
      ) : (
        <div className="space-y-8">
          <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
            <StatCard
              label={t('finance:reports.profitLossReport.totalRevenue')}
              value={formatMoney(totalRevenue)}
            />
            <StatCard
              label={t('finance:reports.profitLossReport.totalExpenses')}
              value={formatMoney(totalExpenses)}
            />
            <StatCard
              label={t('finance:reports.profitLossReport.netIncome')}
              value={formatMoney(netIncome)}
            />
          </div>

          <OwnerChart
            title={t('finance:reports.profitLossReport.revenueVsExpenses')}
            option={revenueVsExpensesOption}
            isEmpty={!data}
          />

          {renderSection(
            t('finance:reports.profitLossReport.revenue'),
            data?.revenue,
            t('finance:reports.profitLossReport.totalRevenue'),
            totalRevenue
          )}

          {renderSection(
            t('finance:reports.profitLossReport.expenses'),
            data?.expenses,
            t('finance:reports.profitLossReport.totalExpenses'),
            totalExpenses
          )}

          {/* Net Income */}
          <div className={cn('border-t-2 pt-4', borderColors.dark)}>
            <div className="flex justify-between text-xl font-bold">
              <span>{t('finance:reports.profitLossReport.netIncome')}</span>
              <span
                className={cn(
                  'tabular-nums',
                  bccomp(netIncome, '0') >= 0
                    ? textColors.success
                    : textColors.error
                )}
              >
                {formatMoney(netIncome)}
              </span>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
