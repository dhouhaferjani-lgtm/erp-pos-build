import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { format } from 'date-fns'
import { Plus, Search, ChevronLeft, ChevronRight, Eye, Smartphone } from 'lucide-react'
import { CountingStatusBadge } from '../components/CountingStatusBadge'
import { useCountingList } from '../api/queries'
import { QueryError } from '@/components/QueryError'
import type { CountingFilters, CountingStatus } from '../types'

const STATUS_OPTIONS: Array<CountingStatus | 'all'> = [
  'all',
  'draft',
  'scheduled',
  'count_1_in_progress',
  'count_1_completed',
  'count_2_in_progress',
  'count_2_completed',
  'count_3_in_progress',
  'count_3_completed',
  'pending_review',
  'finalized',
  'cancelled',
]

export function CountingListPage() {
  const { t } = useTranslation('inventory')
  const [searchParams, setSearchParams] = useSearchParams()

  const [filters, setFilters] = useState<CountingFilters>({
    status: (searchParams.get('status') as CountingStatus | null) ?? 'all',
    search: searchParams.get('search') || '',
    created_on_mobile:
      searchParams.get('mobile') === 'true'
        ? true
        : searchParams.get('mobile') === 'false'
        ? false
        : 'all',
    page: parseInt(searchParams.get('page') || '1', 10),
    per_page: 10,
  })

  const { data, isLoading, error, refetch } = useCountingList(filters)

  const updateFilters = (newFilters: Partial<CountingFilters>) => {
    const updated = { ...filters, ...newFilters, page: 1 }
    setFilters(updated)

    // Update URL params
    const params = new URLSearchParams()
    if (updated.status && updated.status !== 'all') {
      params.set('status', updated.status)
    }
    if (updated.search) {
      params.set('search', updated.search)
    }
    if (updated.created_on_mobile !== 'all') {
      params.set('mobile', String(updated.created_on_mobile))
    }
    setSearchParams(params)
  }

  const goToPage = (page: number) => {
    setFilters({ ...filters, page })
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">{t('counting.list.title')}</h1>
          <p className="text-gray-500">{t('counting.list.description')}</p>
        </div>
        <Link
          to="/inventory/counting/create"
          className="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700"
        >
          <Plus className="w-4 h-4 me-2" />
          {t('counting.new')}
        </Link>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap gap-4 items-center">
        {/* Search */}
        <div className="relative flex-1 min-w-[200px] max-w-md">
          <Search className="absolute start-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
          <input
            type="text"
            placeholder={t('counting.list.searchPlaceholder')}
            value={filters.search || ''}
            onChange={(e) => { updateFilters({ search: e.target.value }); }}
            className="w-full ps-10 pe-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
          />
        </div>

        {/* Status Filter */}
        <select
          value={filters.status || 'all'}
          onChange={(e) =>
            { updateFilters({ status: e.target.value as CountingStatus | 'all' }); }
          }
          className="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
        >
          {STATUS_OPTIONS.map((status) => (
            <option key={status} value={status}>
              {status === 'all'
                ? t('common.allStatuses')
                : t(`counting.status.${status}`)}
            </option>
          ))}
        </select>

        {/* Mobile Filter */}
        <select
          value={filters.created_on_mobile === true ? 'true' : filters.created_on_mobile === false ? 'false' : 'all'}
          onChange={(e) =>
            { updateFilters({
              created_on_mobile:
                e.target.value === 'true' ? true : e.target.value === 'false' ? false : 'all',
            }); }
          }
          className="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
        >
          <option value="all">All Sources</option>
          <option value="true">Mobile Only</option>
          <option value="false">Web Only</option>
        </select>
      </div>

      {/* Table */}
      {isLoading ? (
        <div className="p-8 text-center text-gray-500">
          {t('common.loading')}...
        </div>
      ) : error ? (
        <QueryError
          error={error}
          onRetry={refetch}
          title={t('counting.list.errorLoading')}
        />
      ) : !data || data.data.length === 0 ? (
        <div className="rounded-lg border bg-white py-12 text-center">
          <p className="text-gray-500 mb-4">{t('counting.list.empty')}</p>
          <Link
            to="/inventory/counting/create"
            className="inline-flex items-center px-4 py-2 text-sm font-medium text-blue-600 border border-blue-600 rounded-md hover:bg-blue-50"
          >
            <Plus className="w-4 h-4 me-2" />
            {t('counting.list.createFirst')}
          </Link>
        </div>
      ) : (
        <>
          <div className="border rounded-lg overflow-hidden">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('counting.list.columns.id')}
                  </th>
                  <th className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('counting.list.columns.scope')}
                  </th>
                  <th className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('counting.list.columns.status')}
                  </th>
                  <th className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('counting.list.columns.progress')}
                  </th>
                  <th className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('counting.list.columns.created')}
                  </th>
                  <th className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('common.actions')}
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {data.data.map((counting) => (
                  <tr key={counting.id} className="hover:bg-gray-50">
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span className="text-sm font-mono text-gray-900">
                        #{counting.uuid.slice(0, 8)}
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="flex items-center gap-2">
                        <span className="text-sm text-gray-900">
                          {counting.title || t(`counting.scopeTypes.${counting.scope_type}`)}
                        </span>
                        {counting.created_on_mobile && (
                          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            <Smartphone className="w-3 h-3" />
                            Mobile
                          </span>
                        )}
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <CountingStatusBadge status={counting.status} />
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="flex items-center gap-2">
                        <div className="w-24 bg-gray-200 rounded-full h-2">
                          <div
                            className="bg-blue-600 h-2 rounded-full"
                            style={{ width: `${String(counting.progress.overall)}%` }}
                          />
                        </div>
                        <span className="text-sm text-gray-500">
                          {counting.progress.overall}%
                        </span>
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      {format(new Date(counting.created_at), 'MMM d, yyyy')}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <Link
                        to={`/inventory/counting/${String(counting.id)}`}
                        className="inline-flex items-center px-3 py-1.5 text-sm font-medium text-blue-600 hover:text-blue-700"
                      >
                        <Eye className="w-4 h-4 me-1" />
                        {t('common.view')}
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          {data.meta.last_page > 1 && (
            <div className="flex items-center justify-between">
              <p className="text-sm text-gray-500">
                {t('common.pagination.showing', {
                  from: (data.meta.current_page - 1) * data.meta.per_page + 1,
                  to: Math.min(
                    data.meta.current_page * data.meta.per_page,
                    data.meta.total
                  ),
                  total: data.meta.total,
                })}
              </p>
              <div className="flex gap-2">
                <button
                  type="button"
                  onClick={() => { goToPage(data.meta.current_page - 1); }}
                  disabled={data.meta.current_page === 1}
                  className="inline-flex items-center px-3 py-2 text-sm font-medium border border-gray-300 rounded-md disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-50"
                >
                  <ChevronLeft className="w-4 h-4" />
                </button>
                <button
                  type="button"
                  onClick={() => { goToPage(data.meta.current_page + 1); }}
                  disabled={data.meta.current_page === data.meta.last_page}
                  className="inline-flex items-center px-3 py-2 text-sm font-medium border border-gray-300 rounded-md disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-50"
                >
                  <ChevronRight className="w-4 h-4" />
                </button>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  )
}
