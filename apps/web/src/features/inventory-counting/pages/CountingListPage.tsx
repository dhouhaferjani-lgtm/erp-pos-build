import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { format } from 'date-fns'
import { Plus, Search, ChevronLeft, ChevronRight, Eye, Smartphone } from 'lucide-react'
import { CountingStatusBadge } from '../components/CountingStatusBadge'
import { useCountingList } from '../api/queries'
import { QueryError } from '@/components/QueryError'
import type { CountingFilters, CountingStatus } from '../types'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

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
          <PageHeaderTitle className="text-2xl font-bold">{t('counting.list.title')}</PageHeaderTitle>
          <p className={`${colorTokens.text.subtle}`}>{t('counting.list.description')}</p>
        </div>
        <Link
          to="/inventory/counting/create"
          className={`inline-flex items-center px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrong} rounded-md ${colorTokens.intent.primary.bgStrongHover}`}
        >
          <Plus className="w-4 h-4 me-2" />
          {t('counting.new')}
        </Link>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap gap-4 items-center">
        {/* Search */}
        <div className="relative flex-1 min-w-[200px] max-w-md">
          <Search className={`absolute start-3 top-1/2 -translate-y-1/2 w-4 h-4 ${colorTokens.text.disabled}`} />
          <input
            type="text"
            placeholder={t('counting.list.searchPlaceholder')}
            value={filters.search || ''}
            onChange={(e) => { updateFilters({ search: e.target.value }); }}
            className={`w-full ps-10 pe-4 py-2 border ${colorTokens.border.default} rounded-md focus:outline-none focus:ring-2 ${colorTokens.focus.primaryRing} ${colorTokens.focus.primaryBorder}`}
          />
        </div>

        {/* Status Filter */}
        <select
          value={filters.status || 'all'}
          onChange={(e) =>
            { updateFilters({ status: e.target.value as CountingStatus | 'all' }); }
          }
          className={`px-3 py-2 border ${colorTokens.border.default} rounded-md focus:outline-none focus:ring-2 ${colorTokens.focus.primaryRing} ${colorTokens.focus.primaryBorder}`}
        >
          {STATUS_OPTIONS.map((status) => (
            <option key={status} value={status}>
              {status === 'all'
                ? t('allStatuses')
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
          className={`px-3 py-2 border ${colorTokens.border.default} rounded-md focus:outline-none focus:ring-2 ${colorTokens.focus.primaryRing} ${colorTokens.focus.primaryBorder}`}
        >
          <option value="all">{t('counting.list.sourceFilter.all')}</option>
          <option value="true">{t('counting.list.sourceFilter.mobileOnly')}</option>
          <option value="false">{t('counting.list.sourceFilter.webOnly')}</option>
        </select>
      </div>

      {/* Table */}
      {isLoading ? (
        <div className={`p-8 text-center ${colorTokens.text.subtle}`}>
          {t('loading')}...
        </div>
      ) : error ? (
        <QueryError
          error={error}
          onRetry={refetch}
          title={t('counting.list.errorLoading')}
        />
      ) : !data || data.data.length === 0 ? (
        <div className={`rounded-lg border ${colorTokens.surface.base} py-12 text-center`}>
          <p className={`${colorTokens.text.subtle} mb-4`}>{t('counting.list.empty')}</p>
          <Link
            to="/inventory/counting/create"
            className={`inline-flex items-center px-4 py-2 text-sm font-medium ${colorTokens.intent.primary.text} border ${colorTokens.intent.primary.borderStrong} rounded-md ${colorTokens.intent.primary.bgHover}`}
          >
            <Plus className="w-4 h-4 me-2" />
            {t('counting.list.createFirst')}
          </Link>
        </div>
      ) : (
        <>
          <div className="border rounded-lg overflow-hidden">
            <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
              <thead className={`${colorTokens.surface.page}`}>
                <tr>
                  <th className={`px-6 py-3 text-start text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                    {t('counting.list.columns.id')}
                  </th>
                  <th className={`px-6 py-3 text-start text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                    {t('counting.list.columns.scope')}
                  </th>
                  <th className={`px-6 py-3 text-start text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                    {t('counting.list.columns.status')}
                  </th>
                  <th className={`px-6 py-3 text-start text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                    {t('counting.list.columns.progress')}
                  </th>
                  <th className={`px-6 py-3 text-start text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                    {t('counting.list.columns.created')}
                  </th>
                  <th className={`px-6 py-3 text-start text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                    {t('actionsLabel')}
                  </th>
                </tr>
              </thead>
              <tbody className={`${colorTokens.surface.base} divide-y ${colorTokens.border.divider}`}>
                {data.data.map((counting) => (
                  <tr key={counting.id} className={`${colorTokens.intent.neutral.bgHover}`}>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span className={`text-sm font-mono ${colorTokens.text.primary}`}>
                        #{counting.id.slice(0, 8)}
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="flex items-center gap-2">
                        <span className={`text-sm ${colorTokens.text.primary}`}>
                          {counting.title || t(`counting.scopeTypes.${counting.scope_type}`)}
                        </span>
                        {counting.created_on_mobile && (
                          <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${colorTokens.intent.primary.bgSoft} ${colorTokens.intent.primary.textStronger}`}>
                            <Smartphone className="w-3 h-3" />
                            {t('counting.list.mobileBadge')}
                          </span>
                        )}
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <CountingStatusBadge status={counting.status} />
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="flex items-center gap-2">
                        <div className={`w-24 ${colorTokens.surface.subdued} rounded-full h-2`}>
                          <div
                            className={`${colorTokens.intent.primary.bgStrong} h-2 rounded-full`}
                            style={{ width: `${String(counting.progress.overall)}%` }}
                          />
                        </div>
                        <span className={`text-sm ${colorTokens.text.subtle}`}>
                          {counting.progress.overall}%
                        </span>
                      </div>
                    </td>
                    <td className={`px-6 py-4 whitespace-nowrap text-sm ${colorTokens.text.subtle}`}>
                      {format(new Date(counting.created_at), 'MMM d, yyyy')}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <Link
                        to={`/inventory/counting/${String(counting.id)}`}
                        className={`inline-flex items-center px-3 py-1.5 text-sm font-medium ${colorTokens.intent.primary.text} ${colorTokens.intent.primary.textHoverStrong}`}
                      >
                        <Eye className="w-4 h-4 me-1" />
                        {t('view')}
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </DataTable>
          </div>

          {/* Pagination */}
          {data.meta.last_page > 1 && (
            <div className="flex items-center justify-between">
              <p className={`text-sm ${colorTokens.text.subtle}`}>
                {t('pagination.showing', {
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
                  className={`inline-flex items-center px-3 py-2 text-sm font-medium border ${colorTokens.border.default} rounded-md disabled:opacity-50 disabled:cursor-not-allowed ${colorTokens.intent.neutral.bgHover}`}
                >
                  <ChevronLeft className="w-4 h-4" />
                </button>
                <button
                  type="button"
                  onClick={() => { goToPage(data.meta.current_page + 1); }}
                  disabled={data.meta.current_page === data.meta.last_page}
                  className={`inline-flex items-center px-3 py-2 text-sm font-medium border ${colorTokens.border.default} rounded-md disabled:opacity-50 disabled:cursor-not-allowed ${colorTokens.intent.neutral.bgHover}`}
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
