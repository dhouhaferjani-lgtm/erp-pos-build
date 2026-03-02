import { useMemo } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, Users, Mail, Phone, FileText, Receipt } from 'lucide-react'
import { api } from '../../lib/api'
import { formatCurrency } from '../../lib/formatCurrency'
import { SearchInput } from '../../components/ui/SearchInput'
import { FilterTabs } from '../../components/ui/FilterTabs'
import { SortableTableHeader } from '../../components/ui/SortableTableHeader'
import { OffsetPagination } from '../../components/ui/OffsetPagination'
import { useTableState } from '../../hooks/useTableState'
import { useCompanyStore } from '../../stores/companyStore'

type StatusFilter = 'all' | 'active' | 'inactive'

interface Partner {
  id: string
  name: string
  type: 'customer' | 'supplier' | 'both'
  email: string | null
  phone: string | null
  tax_id: string | null
  is_active: boolean
  receivable_balance: string | null
  credit_balance: string | null
  payable_balance: string | null
  created_at: string
}

interface PartnersResponse {
  data: Partner[]
  meta: {
    total: number
    current_page: number
    per_page: number
    last_page: number
    from: number | null
    to: number | null
  }
  aggregates?: {
    total_partners: number
    total_active: number
    total_receivable: string
    total_payable: string
  }
}

const typeColors = {
  customer: 'bg-blue-100 text-blue-800',
  supplier: 'bg-purple-100 text-purple-800',
  both: 'bg-green-100 text-green-800',
}

export type PartnerType = 'customer' | 'supplier'

interface PartnerListPageProps {
  partnerType?: PartnerType
}

function getNetBalance(partner: Partner, isCustomerView: boolean): number {
  if (isCustomerView || partner.type === 'customer' || partner.type === 'both') {
    const receivable = parseFloat(partner.receivable_balance ?? '0')
    const credit = parseFloat(partner.credit_balance ?? '0')
    return receivable - credit
  }
  return parseFloat(partner.payable_balance ?? '0')
}

export function PartnerListPage({ partnerType }: PartnerListPageProps) {
  const { t, i18n } = useTranslation(['common', 'sales'])
  const location = useLocation()
  const currentCompany = useCompanyStore((s) => s.getCurrentCompany())
  const currency = currentCompany?.currency ?? 'EUR'

  // Determine base path and labels based on partner type
  const isCustomerView = partnerType === 'customer' || location.pathname.includes('/sales/customers')
  const isSupplierView = partnerType === 'supplier' || location.pathname.includes('/purchases/suppliers')

  const basePath = isCustomerView
    ? '/sales/customers'
    : isSupplierView
      ? '/purchases/suppliers'
      : '/partners'

  const pageTitle = isCustomerView
    ? t('navigation.customers')
    : isSupplierView
      ? t('navigation.suppliers')
      : t('sales:partners.title')

  // Get singular entity name for display
  const entitySingular = isCustomerView
    ? t('sales:partners.types.customer')
    : isSupplierView
      ? t('sales:partners.types.supplier')
      : t('sales:partners.title')

  // Get type label function
  const getTypeLabel = (type: 'customer' | 'supplier' | 'both') => {
    return t(`sales:partners.types.${type}`, type)
  }

  const tableState = useTableState({
    defaultSort: { column: 'name', direction: 'asc' },
    defaultFilters: partnerType ? { type: partnerType } : {},
  })

  // Derive status filter from tableState filters
  const isActiveValue = tableState.filters['is_active'] as string | undefined
  const statusFilter: StatusFilter = isActiveValue === '1'
    ? 'active'
    : isActiveValue === '0'
      ? 'inactive'
      : 'all'

  const setStatusFilter = (value: StatusFilter) => {
    if (value === 'all') {
      tableState.removeFilter('is_active')
    } else {
      tableState.setFilter('is_active', value === 'active' ? '1' : '0')
    }
  }

  const searchQuery = (tableState.filters['search'] as string) ?? ''
  const setSearchQuery = (value: string) => {
    if (value) {
      tableState.setFilter('search', value)
    } else {
      tableState.removeFilter('search')
    }
  }

  const hasBalanceFilter = tableState.filters['has_balance'] === '1'
  const toggleHasBalance = () => {
    if (hasBalanceFilter) {
      tableState.removeFilter('has_balance')
    } else {
      tableState.setFilter('has_balance', '1')
    }
  }

  const queryParams = tableState.getQueryParams()
  // Ensure type filter is always set for customer/supplier views
  if (partnerType && !queryParams['type']) {
    queryParams['type'] = partnerType
  }

  const { data, isLoading, error } = useQuery({
    queryKey: ['partners', queryParams],
    queryFn: async () => {
      const params = new URLSearchParams(queryParams)
      const response = await api.get<PartnersResponse>(`/partners?${params.toString()}`)
      return response.data
    },
  })

  const partners = data?.data ?? []
  const meta = data?.meta
  const total = meta?.total ?? partners.length

  // Calculate counts for filter tabs
  const totalPartners = data?.aggregates?.total_partners
  const totalActive = data?.aggregates?.total_active
  const totalInactive = totalPartners !== undefined && totalActive !== undefined
    ? totalPartners - totalActive
    : undefined

  const filterTabs = useMemo(() => {
    const tabs: { value: StatusFilter; label: string; count?: number }[] = [
      { value: 'all', label: t('filters.all') },
      { value: 'active', label: t('filters.active') },
      { value: 'inactive', label: t('filters.inactive') },
    ]
    if (totalPartners !== undefined) tabs[0].count = totalPartners
    if (totalActive !== undefined) tabs[1].count = totalActive
    if (totalInactive !== undefined) tabs[2].count = totalInactive
    return tabs
  }, [t, totalPartners, totalActive, totalInactive])

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">{pageTitle}</h1>
          <p className="text-gray-500">
            {total} {entitySingular.toLowerCase()} {t('total')}
          </p>
        </div>
        <Link
          to={`${basePath}/new`}
          className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
        >
          <Plus className="h-4 w-4" />
          {t('actions.add')} {entitySingular}
        </Link>
      </div>

      {/* Filters */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <FilterTabs tabs={filterTabs} value={statusFilter} onChange={setStatusFilter} />
        <div className="flex items-center gap-3">
          <label className="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
            <input
              type="checkbox"
              checked={hasBalanceFilter}
              onChange={toggleHasBalance}
              className="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
            />
            {t('sales:partners.hasBalance')}
          </label>
          <SearchInput
            value={searchQuery}
            onChange={setSearchQuery}
            placeholder={`${t('actions.search')} ${pageTitle.toLowerCase()}...`}
            className="w-full sm:w-72"
          />
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
      ) : partners.length === 0 ? (
        <div className="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center">
          <Users className="mx-auto h-12 w-12 text-gray-400" />
          <h3 className="mt-2 text-sm font-semibold text-gray-900">
            {searchQuery ? t('status.noResults') : t('sales:partners.empty.title')}
          </h3>
          <p className="mt-1 text-sm text-gray-500">
            {searchQuery
              ? t('status.tryDifferentSearch')
              : t('sales:partners.empty.description')}
          </p>
          {!searchQuery && (
            <div className="mt-6">
              <Link
                to={`${basePath}/new`}
                className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
              >
                <Plus className="h-4 w-4" />
                {t('actions.add')} {entitySingular}
              </Link>
            </div>
          )}
        </div>
      ) : (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <SortableTableHeader
                  column="name"
                  label={t('fields.name')}
                  currentSort={tableState.sortColumn}
                  currentDirection={tableState.sortDirection}
                  onSort={tableState.setSorting}
                />
                {!isCustomerView && !isSupplierView && (
                  <th className="px-4 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('fields.type')}
                  </th>
                )}
                <th className="px-4 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('fields.contact')}
                </th>
                <th className="px-4 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('fields.taxId')}
                </th>
                <SortableTableHeader
                  column={isSupplierView ? 'payable_balance' : 'receivable_balance'}
                  label={t('sales:partners.balance')}
                  currentSort={tableState.sortColumn}
                  currentDirection={tableState.sortDirection}
                  onSort={tableState.setSorting}
                  align="right"
                />
                <th className="px-4 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('fields.status')}
                </th>
                <th className="relative px-4 py-3">
                  <span className="sr-only">{t('table.actionsColumn')}</span>
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {partners.map((partner) => {
                const balance = getNetBalance(partner, isCustomerView)
                const balanceColor =
                  balance > 0
                    ? 'text-red-600 font-medium'
                    : balance < 0
                      ? 'text-green-600 font-medium'
                      : 'text-gray-400'

                return (
                  <tr key={partner.id} className="hover:bg-gray-50">
                    <td className="whitespace-nowrap px-4 py-4">
                      <Link
                        to={`${basePath}/${partner.id}`}
                        className="font-medium text-gray-900 hover:text-blue-600"
                      >
                        {partner.name}
                      </Link>
                    </td>
                    {!isCustomerView && !isSupplierView && (
                      <td className="whitespace-nowrap px-4 py-4">
                        <span
                          className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${typeColors[partner.type]}`}
                        >
                          {getTypeLabel(partner.type)}
                        </span>
                      </td>
                    )}
                    <td className="whitespace-nowrap px-4 py-4 text-sm text-gray-500">
                      <div className="space-y-1">
                        {partner.email && (
                          <div className="flex items-center gap-1">
                            <Mail className="h-3.5 w-3.5" />
                            {partner.email}
                          </div>
                        )}
                        {partner.phone && (
                          <div className="flex items-center gap-1">
                            <Phone className="h-3.5 w-3.5" />
                            {partner.phone}
                          </div>
                        )}
                      </div>
                    </td>
                    <td className="whitespace-nowrap px-4 py-4 text-sm text-gray-500">
                      {partner.tax_id ?? '-'}
                    </td>
                    <td className={`whitespace-nowrap px-4 py-4 text-sm text-end ${balanceColor}`}>
                      {balance !== 0
                        ? formatCurrency(Math.abs(balance), currency, i18n.language)
                        : '-'}
                    </td>
                    <td className="whitespace-nowrap px-4 py-4">
                      <span
                        className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${
                          partner.is_active
                            ? 'bg-green-100 text-green-800'
                            : 'bg-gray-100 text-gray-800'
                        }`}
                      >
                        {partner.is_active ? t('status.active') : t('status.inactive')}
                      </span>
                    </td>
                    <td className="whitespace-nowrap px-4 py-4 text-end text-sm">
                      <div className="flex items-center justify-end gap-2">
                        {isCustomerView && (
                          <>
                            <Link
                              to={`/sales/quotes/new?customer=${partner.id}`}
                              className="text-gray-400 hover:text-blue-600"
                              title={t('actions.newQuote')}
                            >
                              <FileText className="h-4 w-4" />
                            </Link>
                            <Link
                              to={`/sales/invoices/new?customer=${partner.id}`}
                              className="text-gray-400 hover:text-green-600"
                              title={t('actions.newInvoice')}
                            >
                              <Receipt className="h-4 w-4" />
                            </Link>
                          </>
                        )}
                        <Link
                          to={`${basePath}/${partner.id}`}
                          className="text-blue-600 hover:text-blue-900"
                        >
                          {t('actions.view')}
                        </Link>
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>

          {meta && (
            <OffsetPagination
              currentPage={meta.current_page}
              lastPage={meta.last_page}
              total={meta.total}
              perPage={meta.per_page}
              from={meta.from}
              to={meta.to}
              onPageChange={tableState.setPage}
              onPerPageChange={tableState.setPerPage}
            />
          )}
        </div>
      )}
    </div>
  )
}
