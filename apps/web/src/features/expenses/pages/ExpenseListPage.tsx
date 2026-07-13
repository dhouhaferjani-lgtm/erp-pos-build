import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { BarChart3, Download, Plus } from 'lucide-react'
import { toast } from 'sonner'
import { useExpenses, useDeleteExpense, useExpenseAnalytics, usePostExpense } from '../hooks/useExpenses'
import { ExpenseList } from '../components/organisms/ExpenseList'
import { ExpenseCategorySelect } from '../components/molecules/ExpenseCategorySelect'
import { Button } from '@/components/atoms/Button'
import { FormField } from '@/components/atoms/FormField'
import { Select } from '@/components/atoms/Select'
import { ListPageLayout } from '@/components/molecules/ListPageLayout'
import { SearchInput } from '@/components/molecules/SearchInput'
import { QueryError } from '@/components/QueryError'
import { DateRangeFilter } from '@/components/ui/filters/DateRangeFilter'
import { StatCard } from '@/components/ui/StatCard'
import { useCurrency } from '@/hooks/useCurrency'
import { usePermissions } from '@/hooks/usePermissions'
import { formatCurrency } from '@/lib/format'
import { borderColors, colors, textColors } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import { expenseApi } from '../api/expenseApi'
import { downloadExpenseCsv } from '../downloadExpenseCsv'
import type { ExpenseAnalyticsFilters, ExpenseFilters } from '../types'

/**
 * Page: Expense list
 *
 * Main page for viewing and managing expenses.
 * Includes filtering, search, and batch actions.
 *
 * Presentation migrated onto the shared list primitives (ListPageLayout /
 * PageHeader / SearchInput / FormField+Select+Input). The `ExpenseList`
 * organism still renders the table body and the `ExpenseCategorySelect`
 * molecule is reused as-is for the category filter.
 */
export function ExpenseListPage() {
  const { t } = useTranslation(['expenses', 'common'])
  const navigate = useNavigate()
  const { currency } = useCurrency()
  const { hasPermission } = usePermissions()
  const [filters, setFilters] = useState<ExpenseFilters>({ status: 'posted' })
  const [searchTerm, setSearchTerm] = useState('')
  const [isExporting, setIsExporting] = useState(false)

  const { data, isLoading } = useExpenses(filters)
  const analyticsFilters: ExpenseAnalyticsFilters = {
    status: filters.status ?? 'all',
    ...(filters.category_id ? { category_id: filters.category_id } : {}),
    ...(filters.date_from ? { date_from: filters.date_from } : {}),
    ...(filters.date_to ? { date_to: filters.date_to } : {}),
  }
  const analyticsQuery = useExpenseAnalytics(analyticsFilters)
  const deleteExpense = useDeleteExpense()
  const postExpense = usePostExpense()

  const isExpenseStatus = (
    value: string
  ): value is NonNullable<ExpenseFilters['status']> =>
    value === 'draft' ||
    value === 'confirmed' ||
    value === 'posted' ||
    value === 'cancelled'

  const handleSearch = (value: string) => {
    setSearchTerm(value)
    setFilters((prev) => {
      const { search: _omitted, ...rest } = prev
      return value ? { ...rest, search: value } : rest
    })
  }

  const handleFilterChange = (
    key: keyof ExpenseFilters,
    value: string
  ): void => {
    setFilters((prev) => {
      const { [key]: _omitted, ...rest } = prev
      if (!value) {
        return rest
      }
      // ExpenseFilters has mixed value types; narrow the status union explicitly.
      if (key === 'status') {
        return isExpenseStatus(value) ? { ...rest, status: value } : rest
      }
      if (key === 'category_id' || key === 'date_from' || key === 'date_to' || key === 'search') {
        return { ...rest, [key]: value }
      }
      return rest
    })
  }

  const handleDelete = (id: string) => {
    if (confirm(t('expenses:confirmDelete'))) {
      deleteExpense.mutate(id)
    }
  }

  const handlePost = (id: string) => {
    if (confirm(t('expenses:confirmPost'))) {
      postExpense.mutate(id)
    }
  }

  const handleExport = async () => {
    setIsExporting(true)
    try {
      const response = await expenseApi.exportCsv(filters)
      downloadExpenseCsv(response)
    } catch {
      toast.error(t('expenses:analytics.exportError'))
    } finally {
      setIsExporting(false)
    }
  }

  const tiles = analyticsQuery.data?.tiles

  return (
    <ListPageLayout
      title={t('expenses:title')}
      subtitle={t('expenses:description')}
      actions={
        <div className="flex flex-wrap items-center justify-end gap-2">
          <Button
            variant="secondary"
            className="gap-2"
            onClick={() => { void navigate('/expenses/analytics') }}
          >
            <BarChart3 className="h-4 w-4" />
            {t('expenses:analytics.open')}
          </Button>
          {hasPermission('expenses.export') ? (
            <Button
              variant="secondary"
              className="gap-2"
              disabled={isExporting}
              onClick={() => { void handleExport() }}
            >
              <Download className="h-4 w-4" />
              {t('expenses:analytics.export')}
            </Button>
          ) : null}
          <Button
            variant="secondary"
            onClick={() => { void navigate('/expenses/categories') }}
          >
            {t('expenses:categories.title')}
          </Button>
          <Button
            className="gap-2"
            onClick={() => { void navigate('/expenses/new') }}
          >
            <Plus className="h-4 w-4" />
            {t('expenses:createExpense')}
          </Button>
        </div>
      }
      filters={
        <div className="flex w-full flex-col gap-4">
          <SearchInput
            value={searchTerm}
            onChange={handleSearch}
            placeholder={t('expenses:searchPlaceholder')}
            className="w-full sm:w-72"
          />

          <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
            <FormField label={t('expenses:filters.status')} htmlFor="expense-filter-status">
              <Select
                id="expense-filter-status"
                value={filters.status ?? ''}
                onChange={(e) => { handleFilterChange('status', e.target.value) }}
              >
                <option value="">{t('common:all')}</option>
                <option value="draft">{t('expenses:status.draft')}</option>
                <option value="posted">{t('expenses:status.posted')}</option>
              </Select>
            </FormField>

            <FormField label={t('expenses:filters.category')} htmlFor="expense-filter-category">
              <ExpenseCategorySelect
                value={filters.category_id ?? ''}
                onChange={(value) => { handleFilterChange('category_id', value) }}
                placeholder={t('common:all')}
              />
            </FormField>

            <DateRangeFilter
              label={t('expenses:filters.dateRange')}
              fromValue={filters.date_from}
              toValue={filters.date_to}
              onFromChange={(value) => { handleFilterChange('date_from', value ?? '') }}
              onToChange={(value) => { handleFilterChange('date_to', value ?? '') }}
            />
          </div>
        </div>
      }
    >
      <div className="space-y-5">
        <section aria-label={t('expenses:analytics.summary')}>
          {analyticsQuery.isLoading ? (
            <div
              role="status"
              aria-live="polite"
              className={cn(
                'rounded-lg border p-6 text-center text-sm',
                colors.white,
                borderColors.light,
                textColors.tertiary,
              )}
            >
              {t('expenses:analytics.loading')}
            </div>
          ) : analyticsQuery.error ? (
            <QueryError
              error={analyticsQuery.error}
              title={t('expenses:analytics.loadError')}
              onRetry={() => { void analyticsQuery.refetch() }}
              className={cn('rounded-lg border', colors.white, borderColors.light)}
            />
          ) : (
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
              <StatCard
                label={t('expenses:analytics.tiles.total')}
                value={tiles ? formatCurrency(tiles.total, { currency }) : '—'}
              />
              <StatCard
                label={t('expenses:analytics.tiles.count')}
                value={tiles?.count ?? '—'}
              />
              <StatCard
                label={t('expenses:analytics.tiles.unpaid')}
                value={tiles ? formatCurrency(tiles.unpaid_total, { currency }) : '—'}
              />
              <StatCard
                label={t('expenses:analytics.tiles.change')}
                value={tiles?.mom_delta_percent === null || tiles?.mom_delta_percent === undefined
                  ? '—'
                  : `${tiles.mom_delta_percent}%`}
              />
            </div>
          )}
          {searchTerm ? (
            <p className={cn('mt-2 text-xs', textColors.tertiary)}>
              {t('expenses:analytics.searchExcluded')}
            </p>
          ) : null}
        </section>

        <ExpenseList
          expenses={data?.data ?? []}
          isLoading={isLoading}
          onDelete={handleDelete}
          onPost={handlePost}
        />
      </div>
    </ListPageLayout>
  )
}
