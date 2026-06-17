import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Plus } from 'lucide-react'
import { useExpenses, useDeleteExpense, usePostExpense } from '../hooks/useExpenses'
import { ExpenseList } from '../components/organisms/ExpenseList'
import { ExpenseCategorySelect } from '../components/molecules/ExpenseCategorySelect'
import { Button } from '@/components/atoms/Button'
import { FormField } from '@/components/atoms/FormField'
import { Select } from '@/components/atoms/Select'
import { Input } from '@/components/atoms/Input'
import { ListPageLayout } from '@/components/molecules/ListPageLayout'
import { SearchInput } from '@/components/molecules/SearchInput'
import type { ExpenseFilters } from '../types'

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
  const [filters, setFilters] = useState<ExpenseFilters>({})
  const [searchTerm, setSearchTerm] = useState('')

  const { data, isLoading } = useExpenses(filters)
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

  return (
    <ListPageLayout
      title={t('expenses:title')}
      subtitle={t('expenses:description')}
      actions={
        <div className="flex items-center gap-2">
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

            <FormField label={t('expenses:filters.dateFrom')} htmlFor="expense-filter-date-from">
              <Input
                id="expense-filter-date-from"
                type="date"
                value={filters.date_from ?? ''}
                onChange={(e) => { handleFilterChange('date_from', e.target.value) }}
              />
            </FormField>
          </div>
        </div>
      }
    >
      <ExpenseList
        expenses={data?.data ?? []}
        isLoading={isLoading}
        onDelete={handleDelete}
        onPost={handlePost}
      />
    </ListPageLayout>
  )
}
