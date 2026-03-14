import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Plus, Clock, Calendar, Star } from 'lucide-react'
import { useMenus, useDeleteMenu } from '../hooks/useMenus'
import { useTableState } from '../../../hooks/useTableState'
import { SearchFilter } from '../../../components/ui/filters/SearchFilter'
import { FilterTabs } from '../../../components/ui/FilterTabs'
import { OffsetPagination } from '../../../components/ui/OffsetPagination'
import type { MenuData } from '../types/menu'

const DAY_NAMES = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

export function MenuListPage() {
  const { t } = useTranslation(['menu', 'common'])

  const tableState = useTableState({
    defaultPerPage: 25,
    syncToURL: true,
  })

  const statusFilter = (tableState.filters['is_active'] as string) ?? 'all'

  const filterTabs = useMemo(() => [
    { value: 'all', label: t('common:filters.all') },
    { value: 'active', label: t('common:filters.active') },
    { value: 'inactive', label: t('common:filters.inactive') },
  ], [t])

  const queryParams = useMemo(() => {
    const params = tableState.getQueryParams()
    // Map status filter
    const isActive = tableState.filters['is_active'] as string | undefined
    if (isActive === 'active') {
      params['is_active'] = '1'
    } else if (isActive === 'inactive') {
      params['is_active'] = '0'
    }
    return params
  }, [tableState.getQueryParams()])

  const { data, isLoading } = useMenus(queryParams)
  const menus = data?.data ?? []
  const meta = data?.meta

  const deleteMutation = useDeleteMenu()

  const handleDelete = (menu: MenuData) => {
    if (menu.is_default) return
    if (window.confirm(t('menu:confirmDelete', { name: menu.name }))) {
      deleteMutation.mutate(menu.id)
    }
  }

  const formatTimeRange = (menu: MenuData): string | null => {
    if (!menu.active_from && !menu.active_until) return null
    return `${menu.active_from ?? '00:00'} - ${menu.active_until ?? '23:59'}`
  }

  const formatDateRange = (menu: MenuData): string | null => {
    if (!menu.start_date && !menu.end_date) return null
    return `${menu.start_date ?? '...'} - ${menu.end_date ?? '...'}`
  }

  const formatDays = (days: number[] | null): string | null => {
    if (!days || days.length === 0) return null
    return days.map((d) => DAY_NAMES[d - 1]).join(', ')
  }

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">{t('menu:menus')}</h1>
          <p className="text-gray-500">
            {meta?.total ?? 0} {t('common:total')}
          </p>
        </div>
        <Link
          to="/catalog/menus/new"
          className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
        >
          <Plus className="h-4 w-4" />
          {t('menu:createMenu')}
        </Link>
      </div>

      {/* Filters */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <FilterTabs
          tabs={filterTabs}
          value={statusFilter}
          onChange={(v) => tableState.setFilter('is_active', v === 'all' ? undefined : v)}
        />
        <SearchFilter
          value={tableState.filters['search'] as string | undefined}
          onChange={(v) => tableState.setFilter('search', v)}
          placeholder={`${t('common:actions.search')} ${t('menu:menus').toLowerCase()}...`}
          className="w-full sm:w-72"
        />
      </div>

      {/* Content */}
      {isLoading ? (
        <div className="flex items-center justify-center py-12">
          <div className="text-gray-500">{t('common:loading')}</div>
        </div>
      ) : menus.length === 0 ? (
        <div className="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center">
          <h3 className="mt-2 text-sm font-semibold text-gray-900">
            {tableState.hasActiveFilters ? t('common:status.noResults') : t('menu:noMenus')}
          </h3>
          {!tableState.hasActiveFilters && (
            <div className="mt-6">
              <Link
                to="/catalog/menus/new"
                className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
              >
                <Plus className="h-4 w-4" />
                {t('menu:createMenu')}
              </Link>
            </div>
          )}
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {menus.map((menu: MenuData) => (
            <Link
              key={menu.id}
              to={`/catalog/menus/${menu.id}/edit`}
              className="relative block rounded-lg border border-gray-200 bg-white p-6 shadow-sm hover:border-blue-300 hover:shadow-md transition-all"
            >
              {/* Badges */}
              <div className="flex items-center gap-2 mb-3">
                {menu.is_default && (
                  <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-1 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20">
                    <Star className="h-3 w-3" />
                    {t('menu:default')}
                  </span>
                )}
                <span
                  className={`inline-flex items-center rounded-full px-2 py-1 text-xs font-medium ${
                    menu.is_active
                      ? 'bg-green-50 text-green-700 ring-1 ring-inset ring-green-600/20'
                      : 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-600/20'
                  }`}
                >
                  {menu.is_active ? t('common:active') : t('common:inactive')}
                </span>
              </div>

              <h3 className="text-lg font-semibold text-gray-900">{menu.name}</h3>
              {menu.description && (
                <p className="mt-1 text-sm text-gray-500 line-clamp-2">{menu.description}</p>
              )}

              {/* Schedule info */}
              <div className="mt-3 space-y-1">
                {formatTimeRange(menu) && (
                  <div className="flex items-center gap-1.5 text-xs text-gray-500">
                    <Clock className="h-3.5 w-3.5" />
                    {formatTimeRange(menu)}
                  </div>
                )}
                {formatDateRange(menu) && (
                  <div className="flex items-center gap-1.5 text-xs text-gray-500">
                    <Calendar className="h-3.5 w-3.5" />
                    {formatDateRange(menu)}
                  </div>
                )}
                {formatDays(menu.available_days) && (
                  <div className="text-xs text-gray-500">
                    {formatDays(menu.available_days)}
                  </div>
                )}
              </div>

              {/* Stats */}
              <div className="mt-4 flex items-center gap-4 text-sm text-gray-500 border-t border-gray-100 pt-3">
                <span>{menu.categories_count} {t('menu:categories')}</span>
                <span>{menu.items_count} {t('menu:items')}</span>
              </div>

              {/* Delete button (non-default only) */}
              {!menu.is_default && (
                <button
                  type="button"
                  onClick={(e) => {
                    e.preventDefault()
                    e.stopPropagation()
                    handleDelete(menu)
                  }}
                  className="absolute top-4 right-4 text-gray-400 hover:text-red-500 text-xs"
                  title={t('common:delete')}
                >
                  {t('common:delete')}
                </button>
              )}
            </Link>
          ))}
        </div>
      )}

      {/* Pagination */}
      {!isLoading && menus.length > 0 && meta && (
        <OffsetPagination
          currentPage={meta.current_page}
          lastPage={meta.last_page}
          total={meta.total}
          perPage={meta.per_page}
          from={null}
          to={null}
          onPageChange={tableState.setPage}
          onPerPageChange={tableState.setPerPage}
        />
      )}
    </div>
  )
}
