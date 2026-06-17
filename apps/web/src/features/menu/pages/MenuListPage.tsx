import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router-dom'
import { Plus, Clock, Calendar, Star } from 'lucide-react'
import { useMenus, useDeleteMenu } from '../hooks/useMenus'
import { useTableState } from '../../../hooks/useTableState'
import { SearchFilter } from '../../../components/ui/filters/SearchFilter'
import { FilterTabs } from '../../../components/molecules/FilterTabs'
import { OffsetPagination } from '../../../components/ui/OffsetPagination'
import { PageHeader } from '../../../components/molecules/PageHeader'
import { EmptyState } from '../../../components/molecules/EmptyState'
import { Button, StatusBadge, statusTone } from '../../../components/atoms'
import { cn } from '../../../lib/utils'
import { tokens, textColors, borderColors } from '../../../lib/designTokens'
import type { MenuData } from '../types/menu'

const DAY_NAMES = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

export function MenuListPage() {
  const { t } = useTranslation(['menu', 'common'])
  const navigate = useNavigate()

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
      <PageHeader
        title={t('menu:menus')}
        subtitle={`${String(meta?.total ?? 0)} ${t('common:total')}`}
        actions={
          <Button onClick={() => { void navigate('/catalog/menus/new') }}>
            <Plus className="mr-2 h-4 w-4" />
            {t('menu:createMenu')}
          </Button>
        }
        className="mb-0"
      />

      {/* Filters */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <FilterTabs
          tabs={filterTabs}
          value={statusFilter}
          onChange={(v) => { tableState.setFilter('is_active', v === 'all' ? undefined : v); }}
        />
        <SearchFilter
          value={tableState.filters['search'] as string | undefined}
          onChange={(v) => { tableState.setFilter('search', v); }}
          placeholder={`${t('common:actions.search')} ${t('menu:menus').toLowerCase()}...`}
          className="w-full sm:w-72"
        />
      </div>

      {/* Content */}
      {isLoading ? (
        <div className="flex items-center justify-center py-12">
          <div className={textColors.tertiary}>{t('common:loading')}</div>
        </div>
      ) : menus.length === 0 ? (
        <div className="py-6">
          <EmptyState
            title={tableState.hasActiveFilters ? t('common:status.noResults') : t('menu:noMenus')}
            description=""
          />
          {!tableState.hasActiveFilters && (
            <div className="mt-6 flex justify-center">
              <Button onClick={() => { void navigate('/catalog/menus/new') }}>
                <Plus className="mr-2 h-4 w-4" />
                {t('menu:createMenu')}
              </Button>
            </div>
          )}
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {menus.map((menu: MenuData) => (
            <Link
              key={menu.id}
              to={`/catalog/menus/${menu.id}/edit`}
              className={cn(
                'relative block',
                tokens.card.base,
                tokens.card.hover,
                borderColors.hover,
              )}
            >
              {/* Badges */}
              <div className="flex items-center gap-2 mb-3">
                {menu.is_default && (
                  <StatusBadge tone="warning" className="gap-1">
                    <Star className="h-3 w-3" />
                    {t('menu:default')}
                  </StatusBadge>
                )}
                <StatusBadge tone={statusTone(menu.is_active ? 'active' : 'inactive', { inactive: 'danger' })}>
                  {menu.is_active ? t('common:active') : t('common:inactive')}
                </StatusBadge>
              </div>

              <h3 className={cn(tokens.heading.section, 'font-semibold')}>{menu.name}</h3>
              {menu.description && (
                <p className={cn('mt-1 text-sm line-clamp-2', textColors.tertiary)}>{menu.description}</p>
              )}

              {/* Schedule info */}
              <div className="mt-3 space-y-1">
                {formatTimeRange(menu) && (
                  <div className={cn('flex items-center gap-1.5 text-xs', textColors.tertiary)}>
                    <Clock className="h-3.5 w-3.5" />
                    {formatTimeRange(menu)}
                  </div>
                )}
                {formatDateRange(menu) && (
                  <div className={cn('flex items-center gap-1.5 text-xs', textColors.tertiary)}>
                    <Calendar className="h-3.5 w-3.5" />
                    {formatDateRange(menu)}
                  </div>
                )}
                {formatDays(menu.available_days) && (
                  <div className={cn('text-xs', textColors.tertiary)}>
                    {formatDays(menu.available_days)}
                  </div>
                )}
              </div>

              {/* Stats */}
              <div className={cn('mt-4 flex items-center gap-4 text-sm border-t pt-3', textColors.tertiary, borderColors.light)}>
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
                  className={cn('absolute top-4 right-4 text-xs', textColors.disabled, textColors.hoverError)}
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
