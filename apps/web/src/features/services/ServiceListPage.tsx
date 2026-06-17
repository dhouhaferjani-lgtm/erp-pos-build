import { useState, useMemo } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, Wrench, Grid, List, Clock, DollarSign, Percent } from 'lucide-react'
import { api } from '../../lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { useCompany } from '../../hooks/useCompany'
import { SearchInput } from '../../components/molecules/SearchInput'
import { FilterTabs } from '../../components/molecules/FilterTabs'
import type { ServicesResponse, PricingType, CategoriesResponse } from './types'

const pricingTypeColors: Record<PricingType, string> = {
  flat_rate: 'bg-blue-100 text-blue-800',
  hourly: 'bg-purple-100 text-purple-800',
  percentage: 'bg-orange-100 text-orange-800',
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

  const formatCurrency = (amount: string | null) => {
    if (!amount) return '-'
    const currencyCode = currentCompany?.currency ?? 'USD'
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: currencyCode,
    }).format(parseFloat(amount))
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
          <h1 className="text-2xl font-bold text-gray-900">{t('services.title', 'Services')}</h1>
          <p className="text-gray-500">
            {total} {total === 1 ? t('services.serviceCount.singular', 'service') : t('services.serviceCount.plural', 'services')} {t('total', 'total')}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Link
            to="/services/categories"
            className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
          >
            {t('services.manageCategories', 'Manage Categories')}
          </Link>
          <Link
            to="/services/new"
            className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
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
            <div className="flex items-center gap-1 rounded-lg border border-gray-200 p-1">
              <button
                onClick={() => { setViewMode('list') }}
                className={`rounded p-1.5 ${viewMode === 'list' ? 'bg-gray-100 text-gray-900' : 'text-gray-400 hover:text-gray-600'}`}
                title={t('views.list')}
              >
                <List className="h-4 w-4" />
              </button>
              <button
                onClick={() => { setViewMode('grid') }}
                className={`rounded p-1.5 ${viewMode === 'grid' ? 'bg-gray-100 text-gray-900' : 'text-gray-400 hover:text-gray-600'}`}
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
            <span className="text-sm text-gray-500">{t('services.pricingType', 'Pricing')}:</span>
            <div className="flex items-center gap-1">
              {(['all', 'flat_rate', 'hourly', 'percentage'] as PricingFilter[]).map((type) => (
                <button
                  key={type}
                  onClick={() => { setPricingFilter(type) }}
                  className={`rounded-full px-3 py-1 text-xs font-medium transition-colors ${
                    pricingFilter === type
                      ? 'bg-gray-900 text-white'
                      : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
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
              <span className="text-sm text-gray-500">{t('services.category', 'Category')}:</span>
              <select
                value={categoryFilter}
                onChange={(e) => { setCategoryFilter(e.target.value); }}
                className="rounded-lg border border-gray-300 bg-white px-3 py-1 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
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
          <div className="text-gray-500">{t('status.loading')}</div>
        </div>
      ) : error ? (
        <div className="rounded-lg bg-red-50 p-4 text-red-700">
          {t('errors.loadingFailed', 'Error loading data. Please try again.')}
        </div>
      ) : services.length === 0 ? (
        <div className="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center">
          <Wrench className="mx-auto h-12 w-12 text-gray-400" />
          <h3 className="mt-2 text-sm font-semibold text-gray-900">
            {searchQuery ? t('status.noResults') : t('services.noServices', 'No services')}
          </h3>
          <p className="mt-1 text-sm text-gray-500">
            {searchQuery
              ? t('status.tryDifferentSearch', 'Try a different search term.')
              : t('services.noServicesDescription', 'Get started by creating a new service.')}
          </p>
          {!searchQuery && (
            <div className="mt-6">
              <Link
                to="/services/new"
                className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
              >
                <Plus className="h-4 w-4" />
                {t('services.addService', 'Add Service')}
              </Link>
            </div>
          )}
        </div>
      ) : viewMode === 'list' ? (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('services.fields.code', 'Code')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('fields.name', 'Name')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('services.fields.category', 'Category')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('services.pricingType', 'Pricing')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('services.fields.price', 'Price')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('fields.status', 'Status')}
                </th>
                <th className="relative px-6 py-3">
                  <span className="sr-only">{t('actions.actions')}</span>
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {services.map((service) => {
                const PricingIcon = pricingTypeIcons[service.pricing_type]
                return (
                  <tr key={service.id} className="hover:bg-gray-50">
                    <td className="whitespace-nowrap px-6 py-4 text-sm font-mono text-gray-500">
                      {service.code}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4">
                      <Link
                        to={`/services/${service.id}`}
                        className="font-medium text-gray-900 hover:text-blue-600"
                      >
                        {service.name}
                      </Link>
                      {service.description && (
                        <p className="text-sm text-gray-500 truncate max-w-xs">
                          {service.description}
                        </p>
                      )}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
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
                    <td className="whitespace-nowrap px-6 py-4 text-end text-sm font-medium text-gray-900">
                      {service.pricing_type === 'hourly'
                        ? `${formatCurrency(service.hourly_rate)}/h`
                        : service.pricing_type === 'percentage'
                        ? `${service.base_price}%`
                        : formatCurrency(service.base_price)
                      }
                    </td>
                    <td className="whitespace-nowrap px-6 py-4">
                      <span
                        className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${
                          service.is_active
                            ? 'bg-green-100 text-green-800'
                            : 'bg-gray-100 text-gray-800'
                        }`}
                      >
                        {service.is_active ? t('status.active') : t('status.inactive')}
                      </span>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-end text-sm">
                      <Link
                        to={`/services/${service.id}`}
                        className="text-blue-600 hover:text-blue-900"
                      >
                        {t('actions.view')}
                      </Link>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {services.map((service) => {
            const PricingIcon = pricingTypeIcons[service.pricing_type]
            return (
              <Link
                key={service.id}
                to={`/services/${service.id}`}
                className="block rounded-lg border border-gray-200 bg-white p-4 hover:shadow-md transition-shadow"
              >
                <div className="flex items-start justify-between">
                  <div className="flex-1 min-w-0">
                    <p className="text-xs font-mono text-gray-500">{service.code}</p>
                    <h3 className="font-medium text-gray-900 truncate">{service.name}</h3>
                  </div>
                  <span
                    className={`ms-2 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${pricingTypeColors[service.pricing_type]}`}
                  >
                    <PricingIcon className="h-3 w-3" />
                  </span>
                </div>
                {service.category && (
                  <p className="mt-1 text-xs text-gray-500">{service.category.name}</p>
                )}
                {service.description && (
                  <p className="mt-2 text-sm text-gray-500 line-clamp-2">{service.description}</p>
                )}
                <div className="mt-4 flex items-center justify-between">
                  <div className="flex items-center gap-1 text-sm font-medium text-gray-900">
                    <DollarSign className="h-4 w-4 text-gray-400" />
                    {service.pricing_type === 'hourly'
                      ? `${formatCurrency(service.hourly_rate)}/h`
                      : service.pricing_type === 'percentage'
                      ? `${service.base_price}%`
                      : formatCurrency(service.base_price)
                    }
                  </div>
                  <span
                    className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${
                      service.is_active
                        ? 'bg-green-100 text-green-800'
                        : 'bg-gray-100 text-gray-800'
                    }`}
                  >
                    {service.is_active ? t('status.active') : t('status.inactive')}
                  </span>
                </div>
                {service.default_duration_minutes && (
                  <div className="mt-2 flex items-center gap-1 text-xs text-gray-500">
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
