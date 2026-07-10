import { useState, useMemo } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, Wrench, Grid, List, Clock, DollarSign, Percent } from 'lucide-react'
import { api } from '../../lib/api'
import { formatCurrency as formatMoney, formatPercent } from '../../lib/format'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { useCompany } from '../../hooks/useCompany'
import { SearchInput } from '../../components/molecules/SearchInput'
import { FilterTabs } from '../../components/molecules/FilterTabs'
import type { ServicesResponse, PricingType, CategoriesResponse } from './types'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

const pricingTypeColors: Record<PricingType, string> = {
  flat_rate: `${colorTokens.intent.primary.bgSoft} ${colorTokens.intent.primary.textStronger}`,
  hourly: `${colorTokens.intent.accent.bgSoft} ${colorTokens.intent.accent.textStronger}`,
  percentage: `${colorTokens.intent.notice.bgSoft} ${colorTokens.intent.notice.textStronger}`,
}

const pricingTypeIcons: Record<PricingType, typeof DollarSign> = {
  flat_rate: DollarSign,
  hourly: Clock,
  percentage: Percent,
}

type StatusFilter = 'all' | 'active' | 'inactive'
type PricingFilter = 'all' | PricingType
type ViewMode = 'list' | 'grid'

export function ServiceListPage() {
  const { t } = useTranslation()
  const { currentCompany } = useCompany()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const [searchQuery, setSearchQuery] = useState('')
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all')
  const [pricingFilter, setPricingFilter] = useState<PricingFilter>('all')
  const [categoryFilter, setCategoryFilter] = useState<string>('all')
  const [viewMode, setViewMode] = useState<ViewMode>('list')

  const { data: categoriesData } = useQuery({
    queryKey: tenantScopedKey(['service-categories']),
    queryFn: async () => {
      const response = await api.get<CategoriesResponse>('/services/categories')
      return response.data
    },
    enabled: !!tenantId && !!companyId,
  })

  const { data, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['services', searchQuery, statusFilter, pricingFilter, categoryFilter]),
    queryFn: async () => {
      const params = new URLSearchParams()
      if (searchQuery) params.append('search', searchQuery)
      if (statusFilter !== 'all') params.append('is_active', statusFilter === 'active' ? '1' : '0')
      if (pricingFilter !== 'all') params.append('pricing_type', pricingFilter)
      if (categoryFilter !== 'all') params.append('category_id', categoryFilter)
      const queryString = params.toString()
      const response = await api.get<ServicesResponse>(`/services${queryString ? `?${queryString}` : ''}`)
      return response.data
    },
    enabled: !!tenantId && !!companyId,
  })

  const services = data?.data ?? []
  const categories = categoriesData?.data ?? []
  const total = data?.meta?.total ?? services.length

  const filterTabs = useMemo(() => {
    return [
      { value: 'all' as StatusFilter, label: t('filters.all'), count: total },
      { value: 'active' as StatusFilter, label: t('filters.active') },
      { value: 'inactive' as StatusFilter, label: t('filters.inactive') },
    ]
  }, [t, total])

  const formatServiceCurrency = (amount: string | null) => {
    if (!amount) return '-'
    const currencyCode = currentCompany?.currency ?? 'USD'
    return formatMoney(amount, { currency: currencyCode, locale: 'en-US' })
  }

  const formatDuration = (minutes: number | null) => {
    if (!minutes) return '-'
    if (minutes < 60) return `${minutes} min`
    const hours = Math.floor(minutes / 60)
    const remainingMinutes = minutes % 60
    return remainingMinutes > 0 ? `${hours}h ${remainingMinutes}m` : `${hours}h`
  }

  const getPricingTypeLabel = (type: PricingType) => {
    const labels: Record<PricingType, string> = {
      flat_rate: t('services.pricingTypes.flatRate', 'Flat Rate'),
      hourly: t('services.pricingTypes.hourly', 'Hourly'),
      percentage: t('services.pricingTypes.percentage', 'Percentage'),
    }
    return labels[type]
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>{t('services.title', 'Services')}</PageHeaderTitle>
          <p className={`${colorTokens.text.subtle}`}>
            {total} {total === 1 ? t('services.serviceCount.singular', 'service') : t('services.serviceCount.plural', 'services')} {t('total', 'total')}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Link
            to="/services/categories"
            className={`inline-flex items-center gap-2 rounded-lg border ${colorTokens.border.default} bg-white px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} hover:${colorTokens.surface.page} transition-colors`}
          >
            {t('services.manageCategories', 'Manage Categories')}
          </Link>
          <Link
            to="/services/new"
            className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium text-white hover:${colorTokens.intent.primary.bgStrongHover} transition-colors`}
          >
            <Plus className="h-4 w-4" />
            {t('services.addService', 'Add Service')}
          </Link>
        </div>
      </div>

      {/* Filters */}
      <div className="flex flex-col gap-4">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <FilterTabs tabs={filterTabs} value={statusFilter} onChange={setStatusFilter} />
          <div className="flex items-center gap-4">
            <SearchInput
              value={searchQuery}
              onChange={setSearchQuery}
              placeholder={t('services.searchPlaceholder', 'Search services...')}
              className="w-full sm:w-72"
            />
            <div className={`flex items-center gap-1 rounded-lg border ${colorTokens.border.subtle} p-1`}>
              <button
                onClick={() => { setViewMode('list') }}
                className={`rounded p-1.5 ${viewMode === 'list' ? `${colorTokens.surface.muted} ${colorTokens.text.primary}` : `${colorTokens.text.disabled} hover:${colorTokens.text.muted}`}`}
                title={t('views.list')}
              >
                <List className="h-4 w-4" />
              </button>
              <button
                onClick={() => { setViewMode('grid') }}
                className={`rounded p-1.5 ${viewMode === 'grid' ? `${colorTokens.surface.muted} ${colorTokens.text.primary}` : `${colorTokens.text.disabled} hover:${colorTokens.text.muted}`}`}
                title={t('views.grid')}
              >
                <Grid className="h-4 w-4" />
              </button>
            </div>
          </div>
        </div>

        {/* Additional filters row */}
        <div className="flex flex-wrap items-center gap-4">
          {/* Pricing type filter */}
          <div className="flex items-center gap-2">
            <span className={`text-sm ${colorTokens.text.subtle}`}>{t('services.pricingType', 'Pricing')}:</span>
            <div className="flex items-center gap-1">
              {(['all', 'flat_rate', 'hourly', 'percentage'] as PricingFilter[]).map((type) => (
                <button
                  key={type}
                  onClick={() => { setPricingFilter(type) }}
                  className={`rounded-full px-3 py-1 text-xs font-medium transition-colors ${
                    pricingFilter === type
                      ? `${colorTokens.surface.inverseStrong} text-white`
                      : `${colorTokens.surface.muted} ${colorTokens.text.muted} hover:${colorTokens.surface.subdued}`
                  }`}
                >
                  {type === 'all' ? t('filters.all', 'All') : getPricingTypeLabel(type)}
                </button>
              ))}
            </div>
          </div>

          {/* Category filter */}
          {categories.length > 0 && (
            <div className="flex items-center gap-2">
              <span className={`text-sm ${colorTokens.text.subtle}`}>{t('services.category', 'Category')}:</span>
              <select
                value={categoryFilter}
                onChange={(e) => { setCategoryFilter(e.target.value); }}
                className={`rounded-lg border ${colorTokens.border.default} bg-white px-3 py-1 text-sm focus:${colorTokens.intent.primary.borderFocus} focus:outline-none focus:ring-1 focus:${colorTokens.intent.primary.ring}`}
              >
                <option value="all">{t('filters.all', 'All')}</option>
                {categories.map((cat) => (
                  <option key={cat.id} value={cat.id}>{cat.name}</option>
                ))}
              </select>
            </div>
          )}
        </div>
      </div>

      {/* Content */}
      {isLoading ? (
        <div className="flex items-center justify-center py-12">
          <div className={`${colorTokens.text.subtle}`}>{t('status.loading')}</div>
        </div>
      ) : error ? (
        <div className={`rounded-lg ${colorTokens.intent.danger.bgSubtle} p-4 ${colorTokens.intent.danger.textStrong}`}>
          {t('errors.loadingFailed', 'Error loading data. Please try again.')}
        </div>
      ) : services.length === 0 ? (
        <div className={`rounded-lg border-2 border-dashed ${colorTokens.border.default} p-12 text-center`}>
          <Wrench className={`mx-auto h-12 w-12 ${colorTokens.text.disabled}`} />
          <h3 className={`mt-2 text-sm font-semibold ${colorTokens.text.primary}`}>
            {searchQuery ? t('status.noResults') : t('services.noServices', 'No services')}
          </h3>
          <p className={`mt-1 text-sm ${colorTokens.text.subtle}`}>
            {searchQuery
              ? t('status.tryDifferentSearch', 'Try a different search term.')
              : t('services.noServicesDescription', 'Get started by creating a new service.')}
          </p>
          {!searchQuery && (
            <div className="mt-6">
              <Link
                to="/services/new"
                className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium text-white hover:${colorTokens.intent.primary.bgStrongHover}`}
              >
                <Plus className="h-4 w-4" />
                {t('services.addService', 'Add Service')}
              </Link>
            </div>
          )}
        </div>
      ) : viewMode === 'list' ? (
        <div className={`overflow-hidden rounded-lg border ${colorTokens.border.subtle} bg-white`}>
          <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
            <thead className={`${colorTokens.surface.page}`}>
              <tr>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('services.fields.code', 'Code')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('fields.name', 'Name')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('services.fields.category', 'Category')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('services.pricingType', 'Pricing')}
                </th>
                <th className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('services.fields.price', 'Price')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('fields.status', 'Status')}
                </th>
                <th className="relative px-6 py-3">
                  <span className="sr-only">{t('actions.actions')}</span>
                </th>
              </tr>
            </thead>
            <tbody className={`divide-y ${colorTokens.border.divider} bg-white`}>
              {services.map((service) => {
                const PricingIcon = pricingTypeIcons[service.pricing_type]
                return (
                  <tr key={service.id} className={`hover:${colorTokens.surface.page}`}>
                    <td className={`whitespace-nowrap px-6 py-4 text-sm font-mono ${colorTokens.text.subtle}`}>
                      {service.code}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4">
                      <Link
                        to={`/services/${service.id}`}
                        className={`font-medium ${colorTokens.text.primary} hover:${colorTokens.intent.primary.text}`}
                      >
                        {service.name}
                      </Link>
                      {service.description && (
                        <p className={`text-sm ${colorTokens.text.subtle} truncate max-w-xs`}>
                          {service.description}
                        </p>
                      )}
                    </td>
                    <td className={`whitespace-nowrap px-6 py-4 text-sm ${colorTokens.text.subtle}`}>
                      {service.category?.name ?? '-'}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4">
                      <span
                        className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ${pricingTypeColors[service.pricing_type]}`}
                      >
                        <PricingIcon className="h-3 w-3" />
                        {getPricingTypeLabel(service.pricing_type)}
                      </span>
                    </td>
                    <td className={`whitespace-nowrap px-6 py-4 text-end text-sm font-medium ${colorTokens.text.primary}`}>
                      {service.pricing_type === 'hourly'
                        ? `${formatServiceCurrency(service.hourly_rate)}/h`
                        : service.pricing_type === 'percentage'
                        ? formatPercent(service.base_price)
                        : formatServiceCurrency(service.base_price)
                      }
                    </td>
                    <td className="whitespace-nowrap px-6 py-4">
                      <span
                        className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${
                          service.is_active
                            ? `${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.textStronger}`
                            : `${colorTokens.surface.muted} ${colorTokens.text.strong}`
                        }`}
                      >
                        {service.is_active ? t('status.active') : t('status.inactive')}
                      </span>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-end text-sm">
                      <Link
                        to={`/services/${service.id}`}
                        className={`${colorTokens.intent.primary.text} hover:${colorTokens.intent.primary.textStrongest}`}
                      >
                        {t('actions.view')}
                      </Link>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </DataTable>
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {services.map((service) => {
            const PricingIcon = pricingTypeIcons[service.pricing_type]
            return (
              <Link
                key={service.id}
                to={`/services/${service.id}`}
                className={`block rounded-lg border ${colorTokens.border.subtle} bg-white p-4 hover:shadow-md transition-shadow`}
              >
                <div className="flex items-start justify-between">
                  <div className="flex-1 min-w-0">
                    <p className={`text-xs font-mono ${colorTokens.text.subtle}`}>{service.code}</p>
                    <h3 className={`font-medium ${colorTokens.text.primary} truncate`}>{service.name}</h3>
                  </div>
                  <span
                    className={`ms-2 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${pricingTypeColors[service.pricing_type]}`}
                  >
                    <PricingIcon className="h-3 w-3" />
                  </span>
                </div>
                {service.category && (
                  <p className={`mt-1 text-xs ${colorTokens.text.subtle}`}>{service.category.name}</p>
                )}
                {service.description && (
                  <p className={`mt-2 text-sm ${colorTokens.text.subtle} line-clamp-2`}>{service.description}</p>
                )}
                <div className="mt-4 flex items-center justify-between">
                  <div className={`flex items-center gap-1 text-sm font-medium ${colorTokens.text.primary}`}>
                    <DollarSign className={`h-4 w-4 ${colorTokens.text.disabled}`} />
                    {service.pricing_type === 'hourly'
                      ? `${formatServiceCurrency(service.hourly_rate)}/h`
                      : service.pricing_type === 'percentage'
                      ? formatPercent(service.base_price)
                      : formatServiceCurrency(service.base_price)
                    }
                  </div>
                  <span
                    className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${
                      service.is_active
                        ? `${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.textStronger}`
                        : `${colorTokens.surface.muted} ${colorTokens.text.strong}`
                    }`}
                  >
                    {service.is_active ? t('status.active') : t('status.inactive')}
                  </span>
                </div>
                {service.default_duration_minutes && (
                  <div className={`mt-2 flex items-center gap-1 text-xs ${colorTokens.text.subtle}`}>
                    <Clock className="h-3 w-3" />
                    {formatDuration(service.default_duration_minutes)}
                  </div>
                )}
              </Link>
            )
          })}
        </div>
      )}
    </div>
  )
}
