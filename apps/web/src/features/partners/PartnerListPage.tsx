import { useEffect, useMemo } from 'react'
import { usePageTitle } from '../../hooks/usePageTitle'
import { Link, useLocation } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, Users, Mail, Phone, FileText, Receipt, Upload } from 'lucide-react'
import { api } from '../../lib/api'
import { bccomp } from '../../lib/decimal'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { formatCurrency } from '../../lib/formatCurrency'
import { SearchInput } from '../../components/molecules/SearchInput'
import { FilterTabs } from '../../components/molecules/FilterTabs'
import { SortableTableHeader } from '../../components/ui/SortableTableHeader'
import { OffsetPagination } from '../../components/ui/OffsetPagination'
import { useTableState } from '../../hooks/useTableState'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { usePartnerBalanceRealtime } from './hooks/usePartnerBalanceRealtime'
import { getNetBalance } from './partnerNetBalance'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'
import type { OffsetPaginationMeta } from '@/types/pagination'

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
  net_balance: string | null
  created_at: string
}

interface PartnersResponse {
  data: Partner[]
  meta: OffsetPaginationMeta
  aggregates?: {
    total_partners: number
    total_active: number
    total_receivable: string
    total_payable: string
  }
}

const typeColors = {
  customer: `${colorTokens.intent.primary.bgSoft} ${colorTokens.intent.primary.textStronger}`,
  supplier: `${colorTokens.intent.accent.bgSoft} ${colorTokens.intent.accent.textStronger}`,
  both: `${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.textStronger}`,
}

export type PartnerType = 'customer' | 'supplier'

interface PartnerListPageProps {
  partnerType?: PartnerType
}

export function PartnerListPage({ partnerType }: PartnerListPageProps) {
  usePartnerBalanceRealtime()
  const { t, i18n } = useTranslation(['common', 'sales'])
  const location = useLocation()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const currentCompany = useCompanyStore((s) => s.getCurrentCompany())
  const currency = currentCompany?.currency ?? 'EUR'
  const hasTenantScope = tenantId !== null && companyId !== null

  // Determine base path and labels based on partner type
  const isCustomerView = partnerType === 'customer' || location.pathname.includes('/sales/customers')
  const isSupplierView = partnerType === 'supplier' || location.pathname.includes('/purchases/suppliers')

  const pageTitleKey = isCustomerView
    ? 'navigation.customers'
    : isSupplierView
      ? 'navigation.suppliers'
      : 'partners.title'
  usePageTitle(pageTitleKey, isCustomerView || isSupplierView ? 'common' : 'sales')

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

  // BUG-006: Clients and Fournisseurs are the SAME component behind two
  // structurally identical route elements, so react-router reconciles instead
  // of remounting. `useTableState` seeds `defaultFilters` only in its useState
  // initializer, so the filter stays frozen at the previous route's type. The
  // route context is therefore AUTHORITATIVE — it overwrites a stale (or
  // URL-supplied) `type` rather than only filling a missing one.
  const queryParams = tableState.getQueryParams()
  if (partnerType) {
    queryParams['type'] = partnerType
  }

  // Keep the table state (and therefore the synced URL) in step with the route
  // context, so a stale `?type=` is never left bookmarkable on the other list.
  // Page-local on purpose: re-seeding `defaultFilters` inside the shared
  // `useTableState` hook is a separate, wider change.
  const currentTypeFilter = tableState.filters['type']
  const setTableFilter = tableState.setFilter
  useEffect(() => {
    if (partnerType && currentTypeFilter !== partnerType) {
      setTableFilter('type', partnerType)
    }
  }, [partnerType, currentTypeFilter, setTableFilter])

  const { data, isLoading, error } = useQuery({
    // `partnerType` is part of the key (not just of `queryParams`) so the two
    // lists can never share a cache entry even if the params ever stop
    // carrying the type.
    queryKey: tenantScopedKey(['partners', partnerType ?? 'all', queryParams]),
    queryFn: async () => {
      const params = new URLSearchParams(queryParams)
      const response = await api.get<PartnersResponse>(`/partners?${params.toString()}`)
      return response.data
    },
    enabled: hasTenantScope,
  })

  const partners = data?.data ?? []
  const meta = data?.meta
  const total = meta?.total ?? partners.length
  const entityCountLabel = isCustomerView
    ? t('sales:partners.countLabels.customer', { count: total })
    : isSupplierView
      ? t('sales:partners.countLabels.supplier', { count: total })
      : t('sales:partners.countLabels.partner', { count: total })

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
          <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>{pageTitle}</PageHeaderTitle>
          <p className={colorTokens.text.subtle}>
            {t('sales:partners.totalSummary', { count: total, entity: entityCountLabel })}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Link
            to={`/settings/import?entity=${isSupplierView ? 'suppliers' : 'customers'}`}
            className={`inline-flex items-center gap-2 rounded-lg border ${colorTokens.border.default} ${colorTokens.surface.base} px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.intent.neutral.bgHover} transition-colors`}
          >
            <Upload className="h-4 w-4" />
            {t('actions.import')}
          </Link>
          <Link
            to={`${basePath}/new`}
            className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrongHover} transition-colors`}
          >
            <Plus className="h-4 w-4" />
            {t('actions.add')} {entitySingular}
          </Link>
        </div>
      </div>

      {/* Filters */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <FilterTabs tabs={filterTabs} value={statusFilter} onChange={setStatusFilter} />
        <div className="flex items-center gap-3">
          <label className={`flex items-center gap-2 text-sm ${colorTokens.text.muted} cursor-pointer`}>
            <input
              type="checkbox"
              checked={hasBalanceFilter}
              onChange={toggleHasBalance}
              className={`rounded ${colorTokens.border.default} ${colorTokens.intent.primary.text} ${colorTokens.focus.primaryRing}`}
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
          <div className={colorTokens.text.subtle}>{t('status.loading')}</div>
        </div>
      ) : error ? (
        <div className={`rounded-lg ${colorTokens.intent.danger.bgSubtle} p-4 ${colorTokens.intent.danger.textStrong}`}>
          {t('errors.loadingFailed', 'Error loading data. Please try again.')}
        </div>
      ) : partners.length === 0 ? (
        <div className={`rounded-lg border-2 border-dashed ${colorTokens.border.default} p-12 text-center`}>
          <Users className={`mx-auto h-12 w-12 ${colorTokens.text.disabled}`} />
          <h3 className={`mt-2 text-sm font-semibold ${colorTokens.text.primary}`}>
            {searchQuery ? t('status.noResults') : t('sales:partners.empty.title')}
          </h3>
          <p className={`mt-1 text-sm ${colorTokens.text.subtle}`}>
            {searchQuery
              ? t('status.tryDifferentSearch')
              : t('sales:partners.empty.description')}
          </p>
          {!searchQuery && (
            <div className="mt-6">
              <Link
                to={`${basePath}/new`}
                className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrongHover}`}
              >
                <Plus className="h-4 w-4" />
                {t('actions.add')} {entitySingular}
              </Link>
            </div>
          )}
        </div>
      ) : (
        <div className={`overflow-hidden rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base}`}>
          <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
            <thead className={colorTokens.surface.page}>
              <tr>
                <SortableTableHeader
                  column="name"
                  label={t('fields.name')}
                  currentSort={tableState.sortColumn}
                  currentDirection={tableState.sortDirection}
                  onSort={tableState.setSorting}
                />
                {!isCustomerView && !isSupplierView && (
                  <th className={`px-4 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                    {t('fields.type')}
                  </th>
                )}
                <th className={`px-4 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('fields.contact')}
                </th>
                <th className={`px-4 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
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
                <th className={`px-4 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('fields.status')}
                </th>
                <th className="relative px-4 py-3">
                  <span className="sr-only">{t('table.actionsColumn')}</span>
                </th>
              </tr>
            </thead>
            <tbody className={`divide-y ${colorTokens.border.divider} ${colorTokens.surface.base}`}>
              {partners.map((partner) => {
                const balance = getNetBalance(partner, isCustomerView)
                const balanceComparison = bccomp(balance, '0')
                const balanceColor =
                  balanceComparison > 0
                    ? `${colorTokens.intent.danger.text} font-medium`
                    : balanceComparison < 0
                      ? `${colorTokens.intent.success.text} font-medium`
                      : colorTokens.text.disabled
                const displayBalance = balance.startsWith('-') ? balance.slice(1) : balance

                return (
                  <tr key={partner.id} className={colorTokens.intent.neutral.bgHover}>
                    <td className="whitespace-nowrap px-4 py-4">
                      <Link
                        to={`${basePath}/${partner.id}`}
                        className={`font-medium ${colorTokens.text.primary} ${colorTokens.intent.primary.textHover}`}
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
                    <td className={`whitespace-nowrap px-4 py-4 text-sm ${colorTokens.text.subtle}`}>
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
                    <td className={`whitespace-nowrap px-4 py-4 text-sm ${colorTokens.text.subtle}`}>
                      {partner.tax_id ?? '-'}
                    </td>
                    <td className={`whitespace-nowrap px-4 py-4 text-sm text-end ${balanceColor}`}>
                      {balanceComparison !== 0
                        ? formatCurrency(displayBalance, currency, i18n.language)
                        : '-'}
                    </td>
                    <td className="whitespace-nowrap px-4 py-4">
                      <span
                        className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${
                          partner.is_active
                            ? `${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.textStronger}`
                            : `${colorTokens.surface.muted} ${colorTokens.text.strong}`
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
                              className={`${colorTokens.text.disabled} ${colorTokens.intent.primary.textHover}`}
                              title={t('actions.newQuote')}
                            >
                              <FileText className="h-4 w-4" />
                            </Link>
                            <Link
                              to={`/sales/invoices/new?customer=${partner.id}`}
                              className={`${colorTokens.text.disabled} ${colorTokens.intent.success.textHover}`}
                              title={t('actions.newInvoice')}
                            >
                              <Receipt className="h-4 w-4" />
                            </Link>
                          </>
                        )}
                        <Link
                          to={`${basePath}/${partner.id}`}
                          className={`${colorTokens.intent.primary.text} ${colorTokens.intent.primary.textHoverStrongest}`}
                        >
                          {t('actions.view')}
                        </Link>
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </DataTable>

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
