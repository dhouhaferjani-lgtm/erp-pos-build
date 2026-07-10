import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, Tag, Calendar, Package } from 'lucide-react'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { fetchPriceLists } from './api'
import { SearchInput } from '../../components/molecules/SearchInput/SearchInput'
import { FilterTabs } from '../../components/molecules/FilterTabs'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

type StatusFilter = 'all' | 'active' | 'inactive'

export function PriceListListPage() {
  const { t } = useTranslation(['common', 'pricing'])
  const [searchQuery, setSearchQuery] = useState('')
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all')
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  const { data, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['price-lists', statusFilter, searchQuery]),
    queryFn: () =>
      fetchPriceLists({
        ...(statusFilter === 'all' ? {} : { is_active: statusFilter === 'active' }),
        ...(searchQuery ? { search: searchQuery } : {}),
      }),
    enabled: !!tenantId && !!companyId,
  })

  // Search runs server-side (code/name/description); the returned page is the
  // authoritative filtered set — no additional client-side filtering.
  const filteredPriceLists = data?.data ?? []

  const filterTabs = [
    { value: 'all' as StatusFilter, label: t('common:filters.all'), count: filteredPriceLists.length },
    {
      value: 'active' as StatusFilter,
      label: t('common:filters.active'),
      count: filteredPriceLists.filter((p) => p.is_active).length,
    },
    {
      value: 'inactive' as StatusFilter,
      label: t('common:filters.inactive'),
      count: filteredPriceLists.filter((p) => !p.is_active).length,
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
          <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>
            {t('pricing:priceLists.title', 'Price Lists')}
          </PageHeaderTitle>
          <p className={`${colorTokens.text.subtle}`}>
            {filteredPriceLists.length} {filteredPriceLists.length === 1 ? t('pricing:priceLists.singular') : t('pricing:priceLists.plural')}
          </p>
        </div>
        <Link
          to="/pricing/price-lists/new"
          className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium text-white hover:${colorTokens.intent.primary.bgStrongHover} transition-colors`}
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
          <div className={`${colorTokens.text.subtle}`}>{t('common:status.loading')}</div>
        </div>
      ) : error ? (
        <div className={`rounded-lg ${colorTokens.intent.danger.bgSubtle} p-4 ${colorTokens.intent.danger.textStrong}`}>
          {t('common:errors.loadingFailed')}
        </div>
      ) : filteredPriceLists.length === 0 ? (
        <div className={`rounded-lg border-2 border-dashed ${colorTokens.border.default} p-12 text-center`}>
          <Tag className={`mx-auto h-12 w-12 ${colorTokens.text.disabled}`} />
          <h3 className={`mt-2 text-sm font-semibold ${colorTokens.text.primary}`}>
            {searchQuery
              ? t('common:status.noResults')
              : t('pricing:priceLists.empty', 'No price lists')}
          </h3>
          <p className={`mt-1 text-sm ${colorTokens.text.subtle}`}>
            {searchQuery
              ? t('common:status.tryDifferentSearch')
              : t('pricing:priceLists.emptyDescription', 'Create your first price list to get started.')}
          </p>
          {!searchQuery && (
            <div className="mt-6">
              <Link
                to="/pricing/price-lists/new"
                className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium text-white hover:${colorTokens.intent.primary.bgStrongHover}`}
              >
                <Plus className="h-4 w-4" />
                {t('pricing:priceLists.create', 'Create Price List')}
              </Link>
            </div>
          )}
        </div>
      ) : (
        <div className={`overflow-hidden rounded-lg border ${colorTokens.border.subtle} bg-white`}>
          <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
            <thead className={`${colorTokens.surface.page}`}>
              <tr>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('pricing:priceLists.fields.code', 'Code')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('pricing:priceLists.fields.name', 'Name')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('pricing:priceLists.fields.currency', 'Currency')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('pricing:priceLists.fields.validity', 'Validity')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('pricing:priceLists.fields.items', 'Items')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('common:fields.status')}
                </th>
              </tr>
            </thead>
            <tbody className={`divide-y ${colorTokens.border.divider} bg-white`}>
              {filteredPriceLists.map((priceList) => (
                <tr key={priceList.id} className={`hover:${colorTokens.surface.page}`}>
                  <td className="whitespace-nowrap px-6 py-4">
                    <Link
                      to={`/pricing/price-lists/${priceList.id}`}
                      className={`font-mono font-medium ${colorTokens.intent.primary.text} hover:${colorTokens.intent.primary.textStronger}`}
                    >
                      {priceList.code}
                    </Link>
                  </td>
                  <td className="px-6 py-4">
                    <div>
                      <Link
                        to={`/pricing/price-lists/${priceList.id}`}
                        className={`font-medium ${colorTokens.text.primary} hover:${colorTokens.intent.primary.text}`}
                      >
                        {priceList.name}
                      </Link>
                      {priceList.is_default && (
                        <span className={`ms-2 inline-flex rounded-full ${colorTokens.intent.primary.bgSoft} px-2 py-0.5 text-xs font-medium ${colorTokens.intent.primary.textStronger}`}>
                          {t('pricing:priceLists.fields.default')}
                        </span>
                      )}
                      {priceList.description && (
                        <p className={`text-sm ${colorTokens.text.subtle} truncate max-w-xs`}>
                          {priceList.description}
                        </p>
                      )}
                    </div>
                  </td>
                  <td className={`whitespace-nowrap px-6 py-4 text-sm ${colorTokens.text.subtle}`}>
                    {priceList.currency}
                  </td>
                  <td className={`whitespace-nowrap px-6 py-4 text-sm ${colorTokens.text.subtle}`}>
                    <div className="flex items-center gap-1">
                      <Calendar className={`h-4 w-4 ${colorTokens.text.disabled}`} />
                      {priceList.valid_from || priceList.valid_until ? (
                        <span>
                          {formatDate(priceList.valid_from)} - {formatDate(priceList.valid_until)}
                        </span>
                      ) : (
                        <span className={`${colorTokens.text.disabled}`}>
                          {t('pricing:priceLists.noExpiry', 'No expiry')}
                        </span>
                      )}
                    </div>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <div className={`flex items-center gap-1 text-sm ${colorTokens.text.subtle}`}>
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
                          ? `${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.textStronger}`
                          : `${colorTokens.surface.muted} ${colorTokens.text.strong}`
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
          </DataTable>
        </div>
      )}
    </div>
  )
}
