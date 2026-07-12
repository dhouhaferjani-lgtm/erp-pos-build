import type { EChartsOption } from 'echarts'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Button } from '@/components/atoms/Button/Button'
import { QueryError } from '@/components/QueryError'
import { PageHeader } from '@/components/molecules/PageHeader'
import { StatCard } from '@/components/ui/StatCard'
import { FinanceWidget } from '@/features/finance/components/FinanceWidget'
import { EcheancierPanel } from '@/features/finance/components/EcheancierPanel'
import { useProfitLoss } from '@/features/finance/hooks/useProfitLoss'
import { useUpcomingPayments } from '@/features/finance/hooks/useUpcomingPayments'
import { OwnerChart } from '@/features/owner-dashboard/components/OwnerChart'
import {
  cashPositionGroupTotal,
  useCashPosition,
} from '@/features/treasury/hooks/useCashPosition'
import { useCompany } from '@/hooks/useCompany'
import { borderColors, chartColors, colors, textColors, tokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import {
  formatReportCurrency,
  getCurrentMonthStartInputValue,
  getTodayDateInputValue,
} from './reportPageUtils'

type UpcomingPaymentLine =
  App.Modules.Accounting.Application.DTOs.Reports.UpcomingPaymentLineData

function sortByDueDate(lines: UpcomingPaymentLine[]): UpcomingPaymentLine[] {
  return [...lines].sort((first, second) =>
    first.due_date.localeCompare(second.due_date)
  )
}

function bucketCount(
  lines: UpcomingPaymentLine[],
  bucket: 'overdue' | 'current' | 'days1To30' | 'days31To60' | 'days90Plus'
): number {
  return lines.filter((line) => {
    if (bucket === 'overdue') return line.overdue
    if (line.overdue) return false
    if (bucket === 'current') return line.days_until_due === 0
    if (bucket === 'days1To30') {
      return line.days_until_due >= 1 && line.days_until_due <= 30
    }
    if (bucket === 'days31To60') {
      return line.days_until_due >= 31 && line.days_until_due <= 60
    }
    return line.days_until_due > 60
  }).length
}

function addDaysToInputValue(value: string, days: number): string {
  const date = new Date(`${value}T00:00:00Z`)
  date.setUTCDate(date.getUTCDate() + days)
  return date.toISOString().slice(0, 10)
}

interface UpcomingPaymentsPanelProps {
  title: string
  totalLabel: string
  total: string
  emptyLabel: string
  lines: UpcomingPaymentLine[]
  formatMoney: (amount: string) => string
}

function UpcomingPaymentsPanel({
  title,
  totalLabel,
  total,
  emptyLabel,
  lines,
  formatMoney,
}: UpcomingPaymentsPanelProps) {
  const { t } = useTranslation(['finance'])
  const sortedLines = sortByDueDate(lines)
  const buckets = [
    ['overdue', t('finance:overview.upcoming.buckets.overdue')],
    ['current', t('finance:overview.upcoming.buckets.current')],
    ['days1To30', t('finance:overview.upcoming.buckets.days1To30')],
    ['days31To60', t('finance:overview.upcoming.buckets.days31To60')],
    ['days90Plus', t('finance:overview.upcoming.buckets.days90Plus')],
  ] as const

  return (
    <section className={cn('rounded-lg border p-6', colors.white, borderColors.light)}>
      <div className="mb-4 flex items-start justify-between gap-4">
        <div>
          <h2 className={cn(tokens.heading.section)}>{title}</h2>
          <p className={cn('mt-1 text-sm', textColors.tertiary)}>{totalLabel}</p>
        </div>
        <div className={cn('text-end text-xl font-semibold tabular-nums', textColors.primary)}>
          {formatMoney(total)}
        </div>
      </div>

      <div className="mb-4 flex flex-wrap gap-2">
        {buckets.map(([bucket, label]) => (
          <span key={bucket} className={cn(tokens.badge.base, tokens.badge.gray)}>
            {label}: {bucketCount(sortedLines, bucket)}
          </span>
        ))}
      </div>

      {sortedLines.length === 0 ? (
        <p className={cn('py-6 text-center text-sm', textColors.tertiary)}>
          {emptyLabel}
        </p>
      ) : (
        <div className={cn('divide-y', borderColors.divideDefault)}>
          {sortedLines.map((line) => (
            <div
              key={`${line.document_number}-${line.due_date}`}
              data-overdue={line.overdue ? 'true' : 'false'}
              className={cn(
                'grid grid-cols-[1fr_auto] gap-3 border-l-4 py-3 ps-3',
                line.overdue
                  ? cn(colors.error[50], borderColors.leftError)
                  : borderColors.light
              )}
            >
              <div className="min-w-0">
                <p className={cn('truncate text-sm font-medium', textColors.primary)}>
                  {line.partner_name}
                </p>
                <p className={cn('mt-1 text-xs', textColors.tertiary)}>
                  {line.document_number} - {line.type} - {line.due_date}
                </p>
              </div>
              <div className="text-end">
                <p className={cn('text-sm font-semibold tabular-nums', textColors.primary)}>
                  {formatMoney(line.balance_due)}
                </p>
                <p
                  className={cn(
                    'mt-1 text-xs',
                    line.overdue ? textColors.error : textColors.tertiary
                  )}
                >
                  {line.overdue
                    ? t('finance:overview.upcoming.overdue')
                    : t('finance:overview.upcoming.daysUntilDue', {
                        count: line.days_until_due,
                      })}
                </p>
              </div>
            </div>
          ))}
        </div>
      )}
    </section>
  )
}

export function TreasuryOverviewPage() {
  const { t } = useTranslation(['finance'])
  const { currentCompany } = useCompany()
  const cashPositionQuery = useCashPosition()
  const upcomingPaymentsQuery = useUpcomingPayments(30)
  const profitLossQuery = useProfitLoss({
    date_from: getCurrentMonthStartInputValue(),
    date_to: getTodayDateInputValue(),
  })
  const cashPosition = cashPositionQuery.data
  const upcomingPayments = upcomingPaymentsQuery.data
  const profitLoss = profitLossQuery.data
  const maturityFrom = getTodayDateInputValue()
  const maturityTo = addDaysToInputValue(maturityFrom, 30)

  const formatMoney = (amount: string) => formatReportCurrency(amount, currentCompany)
  const revenueVsExpensesOption: EChartsOption = {
    color: [chartColors.primary, chartColors.warning],
    grid: { left: '3%', right: '4%', bottom: '3%', containLabel: true },
    tooltip: { trigger: 'axis' },
    xAxis: {
      type: 'category',
      data: [
        t('finance:overview.trend.revenue'),
        t('finance:overview.trend.expenses'),
      ],
    },
    yAxis: { type: 'value' },
    series: [
      {
        type: 'bar',
        data: [
          profitLoss?.total_revenue ?? '0.000',
          profitLoss?.total_expenses ?? '0.000',
        ],
      },
    ],
  }

  return (
    <div className="space-y-8 p-6">
      <PageHeader
        title={t('finance:overview.title')}
        subtitle={t('finance:overview.subtitle')}
        actions={
          <Link to="/finance/cash-movements">
            <Button variant="secondary">
              {t('finance:cashMovements.navTitle')}
            </Button>
          </Link>
        }
        className="mb-0"
      />

      {cashPositionQuery.isLoading ? (
        <p className={cn('py-8 text-center', textColors.tertiary)}>
          {t('finance:overview.cash.loading')}
        </p>
      ) : cashPositionQuery.error ? (
        <QueryError
          error={cashPositionQuery.error}
          onRetry={() => {
            void cashPositionQuery.refetch()
          }}
          title={t('finance:overview.cash.loadError')}
        />
      ) : (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
          <StatCard
            label={t('finance:overview.cash.totalCash')}
            value={formatMoney(cashPosition?.grand_total ?? '0.000')}
          />
          <StatCard
            label={t('finance:overview.cash.cashRegisters')}
            value={formatMoney(cashPositionGroupTotal(cashPosition, 'cash_register'))}
          />
          <StatCard
            label={t('finance:overview.cash.bankAccounts')}
            value={formatMoney(cashPositionGroupTotal(cashPosition, 'bank_account'))}
          />
          <StatCard
            label={t('finance:overview.cash.safes')}
            value={formatMoney(cashPositionGroupTotal(cashPosition, 'safe'))}
          />
        </div>
      )}

      <FinanceWidget />

      <EcheancierPanel from={maturityFrom} to={maturityTo} formatMoney={formatMoney} />

      {upcomingPaymentsQuery.isLoading ? (
        <p className={cn('py-8 text-center', textColors.tertiary)}>
          {t('finance:overview.upcoming.loading')}
        </p>
      ) : upcomingPaymentsQuery.error ? (
        <QueryError
          error={upcomingPaymentsQuery.error}
          onRetry={() => {
            void upcomingPaymentsQuery.refetch()
          }}
          title={t('finance:overview.upcoming.loadError')}
        />
      ) : (
        <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
          <UpcomingPaymentsPanel
            title={t('finance:overview.upcoming.moneyIn')}
            totalLabel={t('finance:overview.upcoming.totalIn')}
            total={upcomingPayments?.total_in ?? '0.000'}
            emptyLabel={t('finance:overview.upcoming.emptyIn')}
            lines={upcomingPayments?.in ?? []}
            formatMoney={formatMoney}
          />
          <UpcomingPaymentsPanel
            title={t('finance:overview.upcoming.moneyOut')}
            totalLabel={t('finance:overview.upcoming.totalOut')}
            total={upcomingPayments?.total_out ?? '0.000'}
            emptyLabel={t('finance:overview.upcoming.emptyOut')}
            lines={upcomingPayments?.out ?? []}
            formatMoney={formatMoney}
          />
        </div>
      )}

      <OwnerChart
        title={t('finance:overview.trend.revenueVsExpenses')}
        option={revenueVsExpensesOption}
        isLoading={profitLossQuery.isLoading}
        isError={Boolean(profitLossQuery.error)}
        isEmpty={!profitLoss}
      />
    </div>
  )
}
