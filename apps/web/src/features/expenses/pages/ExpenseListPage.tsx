import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Plus, Filter, Search } from 'lucide-react'
import { useExpenses, useDeleteExpense, usePostExpense } from '../hooks/useExpenses'
import { ExpenseList } from '../components/organisms/ExpenseList'
import { ExpenseCategorySelect } from '../components/molecules/ExpenseCategorySelect'
import type { ExpenseFilters } from '../types'

/**
 * Page: Expense list
 *
 * Main page for viewing and managing expenses.
 * Includes filtering, search, and batch actions.
 */
export function ExpenseListPage() {
  const { t } = useTranslation(['expenses', 'common'])
  const [filters, setFilters] = useState<ExpenseFilters>({})
  const [searchTerm, setSearchTerm] = useState('')
  const [showFilters, setShowFilters] = useState(false)

  const { data, isLoading } = useExpenses(filters)
  const deleteExpense = useDeleteExpense()
  const postExpense = usePostExpense()

  const handleSearch = () => {
    setFilters((prev) => {
      const newFilters = { ...prev }
      if (searchTerm) {
        newFilters.search = searchTerm
      } else {
        delete newFilters.search
      }
      return newFilters
    })
  }

  const handleFilterChange = (
    key: keyof ExpenseFilters,
    value: string
  ): void => {
    setFilters((prev) => {
      const newFilters = { ...prev }
      if (value) {
        // TypeScript needs help here because ExpenseFilters has mixed value types
        if (key === 'status') {
          newFilters[key] = value as ExpenseFilters['status']
        } else if (key === 'category_id' || key === 'date_from' || key === 'date_to' || key === 'search') {
          newFilters[key] = value
        }
      } else {
        delete newFilters[key]
      }
      return newFilters
    })
  }

  const handleClearFilters = () => {
    setFilters({})
    setSearchTerm('')
  }

  const handleDelete = async (id: string) => {
    if (confirm(t('expenses:confirmDelete'))) {
      deleteExpense.mutate(id)
    }
  }

  const handlePost = async (id: string) => {
    if (confirm(t('expenses:confirmPost'))) {
      postExpense.mutate(id)
    }
  }

  const activeFilterCount = Object.values(filters).filter(Boolean).length

  return (
    <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
      {/* Header */}
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold text-gray-900 dark:text-gray-100">
            {t('expenses:title')}
          </h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            {t('expenses:description')}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Link
            to="/expenses/categories"
            className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
          >
            {t('expenses:categories.title')}
          </Link>
          <Link
            to="/expenses/new"
            className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
          >
            <Plus className="h-4 w-4" />
            {t('expenses:createExpense')}
          </Link>
        </div>
      </div>

      {/* Search and Filter Bar */}
      <div className="mb-6 space-y-4">
        <div className="flex gap-3">
          <div className="relative flex-1">
            <Search className="absolute start-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
            <input
              type="text"
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
              placeholder={t('expenses:searchPlaceholder')}
              className="w-full rounded-md border border-gray-300 bg-white py-2 pe-3 ps-10 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
            />
          </div>
          <button
            onClick={handleSearch}
            className="rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 transition-colors dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
          >
            {t('common:actions.search')}
          </button>
          <button
            onClick={() => setShowFilters(!showFilters)}
            className="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 transition-colors dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
          >
            <Filter className="h-4 w-4" />
            {t('common:actions.filter')}
            {activeFilterCount > 0 && (
              <span className="inline-flex h-5 w-5 items-center justify-center rounded-full bg-primary-600 text-xs font-medium text-white">
                {activeFilterCount}
              </span>
            )}
          </button>
        </div>

        {/* Filter Panel */}
        {showFilters && (
          <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
              <div>
                <label className="text-sm font-medium text-gray-700 dark:text-gray-300">
                  {t('expenses:filters.status')}
                </label>
                <select
                  value={filters.status || ''}
                  onChange={(e) => handleFilterChange('status', e.target.value)}
                  className="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                >
                  <option value="">{t('common:all')}</option>
                  <option value="draft">{t('expenses:status.draft')}</option>
                  <option value="posted">{t('expenses:status.posted')}</option>
                </select>
              </div>

              <div>
                <ExpenseCategorySelect
                  value={filters.category_id || ''}
                  onChange={(value) => handleFilterChange('category_id', value)}
                  placeholder={t('common:all')}
                />
              </div>

              <div>
                <label className="text-sm font-medium text-gray-700 dark:text-gray-300">
                  {t('expenses:filters.dateFrom')}
                </label>
                <input
                  type="date"
                  value={filters.date_from || ''}
                  onChange={(e) => handleFilterChange('date_from', e.target.value)}
                  className="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                />
              </div>
            </div>

            <div className="mt-4 flex justify-end">
              <button
                onClick={handleClearFilters}
                className="text-sm font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300"
              >
                {t('common:clearFilters')}
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Expense List */}
      <ExpenseList
        expenses={data?.data || []}
        isLoading={isLoading}
        onDelete={handleDelete}
        onPost={handlePost}
      />
    </div>
  )
}
