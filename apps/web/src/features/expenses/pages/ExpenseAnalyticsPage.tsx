import Big from 'big.js'
import { Download, FileText, Receipt, Scale, TrendingUp } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { QueryError } from '@/components/QueryError'
import { Button, Select } from '@/components/atoms'
import { FormField } from '@/components/atoms/FormField'
import { DataTable, type DataTableColumn } from '@/components/molecules/DataTable'
import { PageHeader } from '@/components/molecules/PageHeader'
import { DateRangeFilter } from '@/components/ui/filters/DateRangeFilter'
import { StatCard } from '@/components/ui/StatCard'
import { useCurrency } from '@/hooks/useCurrency'
import { usePermissions } from '@/hooks/usePermissions'
import { borderColors, colors, textColors, tokens } from '@/lib/designTokens'
import { formatCurrency } from '@/lib/format'
import { cn } from '@/lib/utils'
import { expenseApi } from '../api/expenseApi'
import { ExpenseCategorySelect } from '../components/molecules/ExpenseCategorySelect'
import { downloadExpenseCsv } from '../downloadExpenseCsv'
import { useExpenseAnalytics } from '../hooks/useExpenses'
import type {
  ExpenseAnalyticsCategory,
  ExpenseAnalyticsFilters,
  ExpenseAnalyticsVendor,
  ExpenseFilters,
  DocumentStatus,
} from '../types'

interface MonthlyTotal {
  month: string
  total: string
}

function monthlyTotals(
  matrix: { months: Record<string, string> }[],
): MonthlyTotal[] {
  const totals = new Map<string, Big>()

  for (const row of matrix) {
    for (const [month, amount] of Object.entries(row.months)) {
      totals.set(month, (totals.get(month) ?? new Big(0)).plus(amount))
    }
  }

  return [...totals.entries()]
    .sort(([first], [second]) => first.localeCompare(second))
    .map(([month, total]) => ({ month, total: total.toFixed() }))
}

function isExpenseStatus(
  value: string,
): value is DocumentStatus {
  return value === 'draft'
    || value === 'confirmed'
    || value === 'posted'
    || value === 'cancelled'
}

export function ExpenseAnalyticsPage() {
  const { t } = useTranslation(['expenses', 'common'])
  const { currency } = useCurrency()
  const { hasPermission } = usePermissions()
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [categoryId, setCategoryId] = useState('')
  const [status, setStatus] = useState<DocumentStatus>('posted')
  const [isExporting, setIsExporting] = useState(false)

  const filters: ExpenseAnalyticsFilters = {
    ...(dateFrom ? { date_from: dateFrom } : {}),
    ...(dateTo ? { date_to: dateTo } : {}),
    ...(categoryId ? { category_id: categoryId } : {}),
    status,
  }
  const analyticsQuery = useExpenseAnalytics(filters)
  const exportFilters: ExpenseFilters = {
    ...(dateFrom ? { date_from: dateFrom } : {}),
    ...(dateTo ? { date_to: dateTo } : {}),
    ...(categoryId ? { category_id: categoryId } : {}),
    status,
  }
  const analytics = analyticsQuery.data
  const months = [...new Set(
    (analytics?.matrix ?? []).flatMap((row) => Object.keys(row.months)),
  )].sort((first, second) => first.localeCompare(second))
  const monthRows = monthlyTotals(analytics?.matrix ?? [])

  const exportCsv = async () => {
    setIsExporting(true)
    try {
      const response = await expenseApi.exportCsv(exportFilters)
      downloadExpenseCsv(response)
    } catch {
      toast.error(t('expenses:analytics.exportError'))
    } finally {
      setIsExporting(false)
    }
  }

  const categoryColumns: DataTableColumn<ExpenseAnalyticsCategory>[] = [
    {
      key: 'category',
      header: t('expenses:analytics.columns.category'),
      accessor: (row) => row.name,
    },
    {
      key: 'share',
      header: t('expenses:analytics.columns.share'),
      render: (row) => `${row.share_percent}%`,
      numeric: true,
    },
    {
      key: 'total',
      header: t('expenses:analytics.columns.total'),
      render: (row) => formatCurrency(row.total, { currency }),
      numeric: true,
    },
  ]
  const vendorColumns: DataTableColumn<ExpenseAnalyticsVendor>[] = [
    {
      key: 'vendor',
      header: t('expenses:analytics.columns.vendor'),
      accessor: (row) => row.vendor_name,
    },
    {
      key: 'total',
      header: t('expenses:analytics.columns.total'),
      render: (row) => formatCurrency(row.total, { currency }),
      numeric: true,
    },
  ]
  const monthlyColumns: DataTableColumn<MonthlyTotal>[] = [
    {
      key: 'month',
      header: t('expenses:analytics.columns.month'),
      accessor: (row) => row.month,
      cellClassName: 'font-mono',
    },
    {
      key: 'total',
      header: t('expenses:analytics.columns.total'),
      render: (row) => formatCurrency(row.total, { currency }),
      numeric: true,
    },
  ]

  return (
    <div className="space-y-6 p-6">
      <PageHeader
        title={t('expenses:analytics.title')}
        subtitle={t('expenses:analytics.description')}
        className="mb-0"
        actions={hasPermission('expenses.export') ? (
          <Button
            variant="secondary"
            className="gap-2"
            disabled={isExporting}
            onClick={() => { void exportCsv() }}
          >
            <Download className="h-4 w-4" />
            {t('expenses:analytics.export')}
          </Button>
        ) : null}
      />

      <section className={cn('rounded-lg border p-4', colors.white, borderColors.light)}>
        <div className="grid gap-4 md:grid-cols-3">
          <DateRangeFilter
            label={t('expenses:filters.dateRange')}
            fromValue={dateFrom}
            toValue={dateTo}
            onFromChange={(value) => { setDateFrom(value ?? '') }}
            onToChange={(value) => { setDateTo(value ?? '') }}
          />
          <FormField label={t('expenses:filters.category')} htmlFor="expense-analytics-category">
            <ExpenseCategorySelect
              value={categoryId}
              onChange={setCategoryId}
              placeholder={t('common:all')}
            />
          </FormField>
          <FormField label={t('expenses:filters.status')} htmlFor="expense-analytics-status">
            <Select
              id="expense-analytics-status"
              value={status}
              onChange={(event) => {
                if (isExpenseStatus(event.target.value)) {
                  setStatus(event.target.value)
                }
              }}
            >
              <option value="draft">{t('expenses:status.draft')}</option>
              <option value="confirmed">{t('expenses:status.confirmed')}</option>
              <option value="posted">{t('expenses:analytics.defaultPosted')}</option>
              <option value="cancelled">{t('expenses:status.cancelled')}</option>
            </Select>
          </FormField>
        </div>
      </section>

      {analyticsQuery.isLoading ? (
        <p className={cn('py-8 text-center', textColors.tertiary)}>
          {t('expenses:analytics.loading')}
        </p>
      ) : analyticsQuery.error ? (
        <QueryError
          error={analyticsQuery.error}
          title={t('expenses:analytics.loadError')}
          onRetry={() => { void analyticsQuery.refetch() }}
        />
      ) : analytics ? (
        <>
          <section
            aria-label={t('expenses:analytics.summary')}
            className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
          >
            <StatCard
              icon={Receipt}
              label={t('expenses:analytics.tiles.total')}
              value={formatCurrency(analytics.tiles.total, { currency })}
            />
            <StatCard
              icon={FileText}
              label={t('expenses:analytics.tiles.count')}
              value={analytics.tiles.count}
            />
            <StatCard
              icon={Scale}
              label={t('expenses:analytics.tiles.unpaid')}
              value={formatCurrency(analytics.tiles.unpaid_total, { currency })}
            />
            <StatCard
              icon={TrendingUp}
              label={t('expenses:analytics.tiles.change')}
              value={analytics.tiles.mom_delta_percent === null
                ? '—'
                : `${analytics.tiles.mom_delta_percent}%`}
            />
          </section>

          <p className={cn('text-xs', textColors.tertiary)}>
            {t('expenses:analytics.legacyNetCaption')}
          </p>

          <section className={cn('overflow-hidden rounded-lg border', colors.white, borderColors.light)}>
            <div className={cn('border-b px-4 py-3', borderColors.light)}>
              <h2 className={cn('text-base font-semibold', textColors.primary)}>
                {t('expenses:analytics.matrix.title')}
              </h2>
              <p className={cn('mt-1 text-sm', textColors.tertiary)}>
                {t('expenses:analytics.matrix.description')}
              </p>
            </div>
            <div
              data-testid="expense-analytics-matrix"
              dir="auto"
              className="overflow-x-auto"
            >
              <DataTable className="min-w-full">
                <thead className={tokens.table.header}>
                  <tr>
                    <th className={cn('sticky start-0 px-4 py-2 text-start text-xs font-medium uppercase tracking-wide', tokens.table.header, textColors.tertiary)}>
                      {t('expenses:analytics.columns.category')}
                    </th>
                    {months.map((month) => (
                      <th key={month} className={cn('whitespace-nowrap px-4 py-2 text-end text-xs font-medium uppercase tracking-wide', textColors.tertiary)}>
                        {month}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className={cn('divide-y', borderColors.divideDefault)}>
                  {analytics.matrix.map((row) => (
                    <tr key={row.category_id ?? '__uncategorized__'}>
                      <th scope="row" className={cn('sticky start-0 whitespace-nowrap px-4 py-3 text-start text-sm font-medium', colors.white, textColors.primary)}>
                        {row.name}
                      </th>
                      {months.map((month) => (
                        <td key={month} className="whitespace-nowrap px-4 py-3 text-end text-sm tabular-nums">
                          {row.months[month]
                            ? formatCurrency(row.months[month], { currency })
                            : '—'}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </DataTable>
            </div>
          </section>

          <div className="grid gap-6 xl:grid-cols-3">
            <section className={cn('overflow-hidden rounded-lg border', colors.white, borderColors.light)}>
              <h2 className={cn('border-b px-4 py-3 text-base font-semibold', borderColors.light, textColors.primary)}>
                {t('expenses:analytics.categories.title')}
              </h2>
              <DataTable
                columns={categoryColumns}
                data={analytics.by_category}
                keyExtractor={(row) => row.category_id ?? '__uncategorized__'}
                emptyTitle={t('expenses:analytics.empty')}
              />
            </section>
            <section className={cn('overflow-hidden rounded-lg border', colors.white, borderColors.light)}>
              <h2 className={cn('border-b px-4 py-3 text-base font-semibold', borderColors.light, textColors.primary)}>
                {t('expenses:analytics.vendors.title')}
              </h2>
              <DataTable
                columns={vendorColumns}
                data={analytics.top_vendors}
                keyExtractor={(row, index) => row.partner_id ?? `${row.vendor_name}-${String(index)}`}
                emptyTitle={t('expenses:analytics.empty')}
              />
            </section>
            <section className={cn('overflow-hidden rounded-lg border', colors.white, borderColors.light)}>
              <h2 className={cn('border-b px-4 py-3 text-base font-semibold', borderColors.light, textColors.primary)}>
                {t('expenses:analytics.monthly.title')}
              </h2>
              <DataTable
                columns={monthlyColumns}
                data={monthRows}
                keyExtractor={(row) => row.month}
                emptyTitle={t('expenses:analytics.empty')}
              />
            </section>
          </div>
        </>
      ) : null}
    </div>
  )
}
