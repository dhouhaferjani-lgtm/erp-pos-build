import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, Tag, Calendar, Package } from 'lucide-react'
import { fetchPriceLists } from './api'
import { SearchInput } from '../../components/ui/SearchInput'
import { FilterTabs } from '../../components/ui/FilterTabs'

type StatusFilter = 'all' | 'active' | 'inactive'

export function PriceListListPage() {
  const { t } = useTranslation(['common', 'pricing'])
  const [searchQuery, setSearchQuery] = useState('')
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all')

  const { data, isLoading, error } = useQuery({
    queryKey: ['price-lists', statusFilter],
    queryFn: () =>
      fetchPriceLists(
        statusFilter === 'all' ? undefined : { is_active: statusFilter === 'active' }
      ),
  })

  const priceLists = data?.data ?? []

  // Filter by search query
  const filteredPriceLists = priceLists.filter((priceList) => {
    if (!searchQuery) return true
    const query = searchQuery.toLowerCase()
    return (
      priceList.code.toLowerCase().includes(query) ||
      priceList.name.toLowerCase().includes(query) ||
      priceList.description?.toLowerCase().includes(query)
    )
  })

  const filterTabs = [
    { value: 'all' as StatusFilter, label: t('common:filters.all'), count: priceLists.length },
    {
      value: 'active' as StatusFilter,
      label: t('common:filters.active'),
      count: priceLists.filter((p) => p.is_active).length,
    },
    {
      value: 'inactive' as StatusFilter,
      label: t('common:filters.inactive'),
      count: priceLists.filter((p) => !p.is_active).length,
    },
  ]

  const formatDate = (dateString: string | null) => {
    if (!dateString) return '-'
    return new Date(dateString).toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    })
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">
            {t('pricing:priceLists.title', 'Price Lists')}
          </h1>
          <p className="text-gray-500">
            {filteredPriceLists.length} {filteredPriceLists.length === 1 ? t('pricing:priceLists.singular') : t('pricing:priceLists.plural')}
          </p>
        </div>
        <Link
          to="/pricing/price-lists/new"
          className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
        >
          <Plus className="h-4 w-4" />
          {t('pricing:priceLists.create', 'Create Price List')}
        </Link>
      </div>

      {/* Filters */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <FilterTabs tabs={filterTabs} value={statusFilter} onChange={setStatusFilter} />
        <SearchInput
          value={searchQuery}
          onChange={setSearchQuery}
          placeholder={t('common:actions.search')}
          className="w-full sm:w-72"
        />
      </div>

      {/* Content */}
      {isLoading ? (
        <div className="flex items-center justify-center py-12">
          <div className="text-gray-500">{t('common:status.loading')}</div>
        </div>
      ) : error ? (
        <div className="rounded-lg bg-red-50 p-4 text-red-700">
          {t('common:errors.loadingFailed')}
        </div>
      ) : filteredPriceLists.length === 0 ? (
        <div className="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center">
          <Tag className="mx-auto h-12 w-12 text-gray-400" />
          <h3 className="mt-2 text-sm font-semibold text-gray-900">
            {searchQuery
              ? t('common:status.noResults')
              : t('pricing:priceLists.empty', 'No price lists')}
          </h3>
          <p className="mt-1 text-sm text-gray-500">
            {searchQuery
              ? t('common:status.tryDifferentSearch')
              : t('pricing:priceLists.emptyDescription', 'Create your first price list to get started.')}
          </p>
          {!searchQuery && (
            <div className="mt-6">
              <Link
                to="/pricing/price-lists/new"
                className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
              >
                <Plus className="h-4 w-4" />
                {t('pricing:priceLists.create', 'Create Price List')}
              </Link>
            </div>
          )}
        </div>
      ) : (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('pricing:priceLists.fields.code', 'Code')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('pricing:priceLists.fields.name', 'Name')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('pricing:priceLists.fields.currency', 'Currency')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('pricing:priceLists.fields.validity', 'Validity')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('pricing:priceLists.fields.items', 'Items')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('common:fields.status')}
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {filteredPriceLists.map((priceList) => (
                <tr key={priceList.id} className="hover:bg-gray-50">
                  <td className="whitespace-nowrap px-6 py-4">
                    <Link
                      to={`/pricing/price-lists/${priceList.id}`}
                      className="font-mono font-medium text-blue-600 hover:text-blue-800"
                    >
                      {priceList.code}
                    </Link>
                  </td>
                  <td className="px-6 py-4">
                    <div>
                      <Link
                        to={`/pricing/price-lists/${priceList.id}`}
                        className="font-medium text-gray-900 hover:text-blue-600"
                      >
                        {priceList.name}
                      </Link>
                      {priceList.is_default && (
                        <span className="ms-2 inline-flex rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800">
                          {t('pricing:priceLists.fields.default')}
                        </span>
                      )}
                      {priceList.description && (
                        <p className="text-sm text-gray-500 truncate max-w-xs">
                          {priceList.description}
                        </p>
                      )}
                    </div>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                    {priceList.currency}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                    <div className="flex items-center gap-1">
                      <Calendar className="h-4 w-4 text-gray-400" />
                      {priceList.valid_from || priceList.valid_until ? (
                        <span>
                          {formatDate(priceList.valid_from)} - {formatDate(priceList.valid_until)}
                        </span>
                      ) : (
                        <span className="text-gray-400">
                          {t('pricing:priceLists.noExpiry', 'No expiry')}
                        </span>
                      )}
                    </div>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <div className="flex items-center gap-1 text-sm text-gray-500">
                      <Package className="h-4 w-4" />
                      <span>
                        {(priceList.items_count ?? 0) === 1
                          ? t('pricing:priceLists.itemCountOne', { count: 1 })
                          : t('pricing:priceLists.itemCount', { count: priceList.items_count ?? 0 })}
                      </span>
                    </div>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <span
                      className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${
                        priceList.is_active
                          ? 'bg-green-100 text-green-800'
                          : 'bg-gray-100 text-gray-800'
                      }`}
                    >
                      {priceList.is_active
                        ? t('common:filters.active', 'Active')
                        : t('common:filters.inactive', 'Inactive')}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
