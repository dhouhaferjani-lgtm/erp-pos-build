import { useState, useMemo } from 'react'
import { usePageTitle } from '../../hooks/usePageTitle'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, FileText, Calendar } from 'lucide-react'
import { api } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { cn } from '../../lib/utils'
import { tokens, textColors, borderColors } from '../../lib/designTokens'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { formatCurrency } from '../../lib/format'
import { SearchInput } from '../../components/ui/SearchInput'
import { FilterTabs } from '../../components/ui/FilterTabs'
import { OffsetPagination } from '../../components/ui/OffsetPagination'
import { Button, StatusBadge, statusTone, type StatusTone } from '../../components/atoms'
import {
  DataTable,
  type DataTableColumn,
  EmptyState,
  ListPageLayout,
} from '../../components/molecules'
import type { Document } from '../../types/document'

interface DocumentsResponse {
  data: Document[]
  meta?: {
    total?: number
    current_page?: number
    last_page?: number
    per_page?: number
    from?: number | null
    to?: number | null
  }
}

export type DocumentType = 'quote' | 'sales_order' | 'invoice' | 'purchase_order' | 'delivery_note' | 'credit_note' | 'return_note'

/**
 * Document workflow statuses that aren't in the shared `statusTone` built-in
 * map get a semantic tone here, so every document status renders through the
 * one sanctioned `StatusBadge` palette instead of a bespoke off-theme map.
 * (`draft` → pending, `cancelled` → danger are already built in.)
 */
const documentStatusTones: Record<string, StatusTone> = {
  confirmed: 'info',
  posted: 'success',
  received: 'info',
}

// Map document types to navigation paths
const documentTypeToPath: Record<DocumentType, string> = {
  quote: '/sales/quotes',
  sales_order: '/sales/orders',
  invoice: '/sales/invoices',
  purchase_order: '/purchases/orders',
  delivery_note: '/inventory/delivery-notes',
  credit_note: '/sales/credit-notes',
  return_note: '/inventory/return-notes',
}

// Map document types to translation keys (navigation titles)
const documentTypeToTitleKey: Record<DocumentType, string> = {
  quote: 'navigation.quotes',
  sales_order: 'navigation.salesOrders',
  invoice: 'navigation.invoices',
  purchase_order: 'navigation.purchaseOrders',
  delivery_note: 'navigation.deliveryNotes',
  credit_note: 'navigation.creditNotes',
  return_note: 'navigation.returnNotes',
}

// Map document types to their API endpoints
const documentTypeToApiEndpoint: Record<DocumentType, string> = {
  quote: '/quotes',
  sales_order: '/orders',
  invoice: '/invoices',
  purchase_order: '/purchase-orders',
  delivery_note: '/delivery-notes',
  credit_note: '/credit-notes',
  return_note: '/return-notes',
}

function getDocumentTypeFromPath(pathname: string): DocumentType | undefined {
  if (pathname.includes('/sales/quotes')) return 'quote'
  if (pathname.includes('/sales/orders')) return 'sales_order'
  if (pathname.includes('/sales/invoices')) return 'invoice'
  if (pathname.includes('/purchases/orders')) return 'purchase_order'
  if (pathname.includes('/inventory/delivery-notes')) return 'delivery_note'
  if (pathname.includes('/inventory/return-notes')) return 'return_note'
  if (pathname.includes('/sales/credit-notes')) return 'credit_note'
  return undefined
}

type StatusFilter = 'all' | 'draft' | 'confirmed' | 'posted' | 'received' | 'cancelled'
type PaymentStatusFilter = 'all' | 'unpaid' | 'partially_paid' | 'paid' | 'overdue'

interface DocumentListPageProps {
  documentType?: DocumentType
}

export function DocumentListPage({ documentType }: DocumentListPageProps) {
  const { t } = useTranslation()
  usePageTitle('documents.title', 'sales')
  const location = useLocation()
  const navigate = useNavigate()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const currentCompany = useCompanyStore((state) => state.getCurrentCompany())
  const [searchQuery, setSearchQuery] = useState('')
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all')
  const [paymentStatusFilter, setPaymentStatusFilter] = useState<PaymentStatusFilter>('all')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)

  // Reset to the first page whenever the result set changes shape.
  const handleStatusFilterChange = (value: StatusFilter) => {
    setStatusFilter(value)
    setPage(1)
  }
  const handleSearchChange = (value: string) => {
    setSearchQuery(value)
    setPage(1)
  }

  // Get company currency with fallback
  const companyCurrency = currentCompany?.currency ?? 'EUR'
  const companyLocale = currentCompany?.locale?.replace('_', '-') ?? 'en-US'

  // Determine the document type from props or URL path
  const effectiveType = documentType ?? getDocumentTypeFromPath(location.pathname)

  const basePath = effectiveType ? documentTypeToPath[effectiveType] : '/documents'
  const apiEndpoint = effectiveType ? documentTypeToApiEndpoint[effectiveType] : '/documents'
  const pageTitle = effectiveType ? t(documentTypeToTitleKey[effectiveType]) : t('sales:documents.title')

  // Get translated type and status labels
  const getTypeLabel = (type: string | undefined) => {
    if (!type) return t('common:unknown')
    return t(`sales:documents.types.${type.replace(/_/g, '')}`, t(`sales:documents.types.${type}`, type))
  }
  const getStatusLabel = (status: string) => t(`status.${status}`, status)

  const { data, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['documents', effectiveType, searchQuery, statusFilter, page, perPage]),
    queryFn: async () => {
      const params = new URLSearchParams()
      if (searchQuery) params.append('search', searchQuery)
      if (statusFilter !== 'all') params.append('status', statusFilter)
      params.append('page', String(page))
      params.append('per_page', String(perPage))
      const queryString = params.toString()
      const response = await api.get<DocumentsResponse>(`${apiEndpoint}${queryString ? `?${queryString}` : ''}`)
      return response.data
    },
    enabled: tenantId !== null && companyId !== null && apiEndpoint !== '/documents',
  })

  // Apply payment status filter (client-side for now)
  const documents = useMemo(() => {
    const allDocs = data?.data ?? []

    // Only filter by payment status for invoices
    if (effectiveType !== 'invoice' || paymentStatusFilter === 'all') {
      return allDocs
    }

    return allDocs.filter((doc) => {
      // Only filter posted invoices
      if (doc.status !== 'posted') return true

      const balanceDue = parseFloat(doc.balance_due ?? doc.total ?? '0')
      const total = parseFloat(doc.total ?? '0')

      switch (paymentStatusFilter) {
        case 'paid':
          return balanceDue === 0
        case 'unpaid':
          return balanceDue === total && balanceDue > 0
        case 'partially_paid':
          return balanceDue > 0 && balanceDue < total
        case 'overdue':
          if (!doc.due_date) return false
          const dueDate = new Date(doc.due_date)
          const today = new Date()
          today.setHours(0, 0, 0, 0)
          dueDate.setHours(0, 0, 0, 0)
          return dueDate < today && balanceDue > 0
        default:
          return true
      }
    })
  }, [data?.data, effectiveType, paymentStatusFilter])

  // Server pagination metadata (Laravel paginator). Falls back gracefully when
  // an endpoint returns only a `total`.
  const meta = data?.meta
  const total = meta?.total ?? documents.length
  const currentPage = meta?.current_page ?? page
  const lastPage = meta?.last_page ?? 1
  const perPageActual = meta?.per_page ?? perPage
  const rangeFrom = meta?.from ?? null
  const rangeTo = meta?.to ?? null

  // Format currency using company settings
  const formatAmount = (amount: string | number | null) => {
    const num = typeof amount === 'string' ? parseFloat(amount) : (amount ?? 0)
    if (isNaN(num)) return formatCurrency(0, { currency: companyCurrency, locale: companyLocale })
    return formatCurrency(num, {
      currency: companyCurrency,
      locale: companyLocale,
    })
  }

  // Filter tabs configuration - show received only for purchase orders
  const filterTabs = useMemo(() => {
    const tabs = [
      { value: 'all' as StatusFilter, label: t('filters.all'), count: total },
      { value: 'draft' as StatusFilter, label: t('status.draft') },
      { value: 'confirmed' as StatusFilter, label: t('status.confirmed') },
      { value: 'posted' as StatusFilter, label: t('status.posted') },
    ]
    // Add received filter for purchase orders
    if (effectiveType === 'purchase_order') {
      tabs.push({ value: 'received' as StatusFilter, label: t('status.received', 'Received') })
    }
    tabs.push({ value: 'cancelled' as StatusFilter, label: t('status.cancelled') })
    return tabs
  }, [t, total, effectiveType])

  // Payment status filter tabs (only for invoices)
  const paymentFilterTabs = useMemo(() => {
    if (effectiveType !== 'invoice') return []

    return [
      { value: 'all' as PaymentStatusFilter, label: t('filters.all') },
      { value: 'unpaid' as PaymentStatusFilter, label: t('sales:invoices.paymentStatus.unpaid') },
      { value: 'partially_paid' as PaymentStatusFilter, label: t('sales:invoices.paymentStatus.partially_paid') },
      { value: 'paid' as PaymentStatusFilter, label: t('sales:invoices.paymentStatus.paid') },
      { value: 'overdue' as PaymentStatusFilter, label: t('sales:documents.statuses.overdue') },
    ]
  }, [t, effectiveType])


  // Helper function to determine payment status for invoices
  const getPaymentStatus = (doc: Document): { label: string; tone: StatusTone; key: string } | null => {
    // Only show payment status for posted invoices
    if (doc.type !== 'invoice' || doc.status !== 'posted') {
      return null
    }

    const balanceDue = parseFloat(doc.balance_due ?? doc.total ?? '0')
    const total = parseFloat(doc.total ?? '0')

    // Fully paid
    if (balanceDue === 0) {
      return { label: t('sales:documents.statuses.paid'), tone: 'success', key: 'paid' }
    }

    // Check if overdue
    if (doc.due_date) {
      const dueDate = new Date(doc.due_date)
      const today = new Date()
      today.setHours(0, 0, 0, 0)
      dueDate.setHours(0, 0, 0, 0)

      if (dueDate < today && balanceDue > 0) {
        return { label: t('sales:documents.statuses.overdue'), tone: 'danger', key: 'overdue' }
      }
    }

    // Partially paid
    if (balanceDue > 0 && balanceDue < total) {
      return { label: t('sales:documents.statuses.partial'), tone: 'warning', key: 'partial' }
    }

    // Unpaid
    return { label: t('sales:documents.statuses.unpaid'), tone: 'warning', key: 'unpaid' }
  }

  // Get translated singular name for button and count
  const getEntitySingular = () => {
    if (!effectiveType) return t('sales:documents.title')
    const singularKeys: Record<DocumentType, string> = {
      quote: 'sales:documents.types.quote',
      sales_order: 'sales:documents.types.salesOrder',
      invoice: 'sales:documents.types.invoice',
      purchase_order: 'sales:documents.types.purchaseOrder',
      delivery_note: 'sales:documents.types.deliveryNote',
      credit_note: 'sales:documents.types.creditNote',
      return_note: 'sales:documents.types.returnNote',
    }
    return t(singularKeys[effectiveType])
  }
  const entitySingular = getEntitySingular()

  const addPath =
    documentType === 'credit_note' ? `${basePath}/create` : `${basePath}/new`

  const columns: DataTableColumn<Document>[] = [
    {
      key: 'number',
      header: t('sales:documents.number'),
      render: (doc) => (
        <Link
          to={`${basePath}/${doc.id}`}
          className={cn('font-medium', textColors.brand, 'hover:underline')}
        >
          {doc.document_number}
        </Link>
      ),
    },
    {
      key: 'type',
      header: t('sales:documents.type'),
      render: (doc) => (
        <StatusBadge tone="neutral">{getTypeLabel(doc.type)}</StatusBadge>
      ),
    },
    {
      // `sales:documents.status` is an object of status *values*; the column
      // label is the generic "Status" string.
      key: 'status',
      header: t('common:fields.status'),
      render: (doc) => {
        const paymentStatus = getPaymentStatus(doc)
        return (
          <div className="flex flex-col items-start gap-1">
            <StatusBadge tone={statusTone(doc.status, documentStatusTones)}>
              {getStatusLabel(doc.status)}
            </StatusBadge>
            {paymentStatus && (
              <StatusBadge tone={paymentStatus.tone}>
                {t(`sales:documents.statuses.${paymentStatus.key}`, paymentStatus.label)}
              </StatusBadge>
            )}
          </div>
        )
      },
    },
    {
      key: 'partner',
      header: t('sales:documents.partner'),
      render: (doc) =>
        doc.partner_id ? (
          <Link
            to={
              doc.type === 'purchase_order'
                ? `/purchases/suppliers/${doc.partner_id}`
                : `/sales/customers/${doc.partner_id}`
            }
            className={cn(textColors.brand, 'hover:underline')}
          >
            {doc.partner_name ?? t('sales:partners.unknown')}
          </Link>
        ) : (
          <span className={textColors.tertiary}>
            {doc.partner_name ?? t('sales:partners.unknown')}
          </span>
        ),
    },
    {
      key: 'date',
      header: t('sales:documents.date'),
      render: (doc) => (
        <div className={cn('flex items-center gap-1', textColors.tertiary)}>
          <Calendar className="h-3.5 w-3.5" />
          {new Date(doc.document_date).toLocaleDateString()}
        </div>
      ),
    },
    {
      key: 'total',
      header: t('sales:documents.total'),
      numeric: true,
      cellClassName: 'font-medium',
      render: (doc) => formatAmount(doc.total ?? 0),
    },
  ]

  if (effectiveType === 'invoice') {
    columns.push({
      key: 'balance',
      header: t('sales:documents.balanceDue'),
      numeric: true,
      cellClassName: 'font-medium',
      render: (doc) => {
        const paymentStatus = getPaymentStatus(doc)
        return (
          <span
            className={
              paymentStatus?.key === 'overdue'
                ? cn('font-semibold', textColors.error)
                : undefined
            }
          >
            {formatAmount(doc.balance_due ?? doc.total ?? 0)}
          </span>
        )
      },
    })
  }

  columns.push({
    key: 'actions',
    header: <span className="sr-only">{t('table.actionsColumn')}</span>,
    align: 'right',
    render: (doc) => (
      <Link to={`${basePath}/${doc.id}`} className={textColors.brand}>
        {t('actions.view')}
      </Link>
    ),
  })

  return (
    <ListPageLayout
      title={pageTitle}
      subtitle={`${total} ${entitySingular.toLowerCase()} ${t('total')}`}
      actions={
        <Button className="gap-2" onClick={() => { void navigate(addPath) }}>
          <Plus className="h-4 w-4" />
          {t('actions.add')} {entitySingular}
        </Button>
      }
      filters={
        <div className="flex w-full flex-col gap-4">
          <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <FilterTabs tabs={filterTabs} value={statusFilter} onChange={handleStatusFilterChange} />
            <SearchInput
              value={searchQuery}
              onChange={handleSearchChange}
              placeholder={`${t('actions.search')} ${pageTitle.toLowerCase()}...`}
              className="w-full sm:w-72"
            />
          </div>

          {/* Payment Status Filter (Invoices only) */}
          {effectiveType === 'invoice' && paymentFilterTabs.length > 0 && (
            <div className={cn('border-t pt-4', borderColors.light)}>
              <label className={cn('mb-2 block', tokens.label.base)}>
                {t('sales:documents.paymentStatus')}
              </label>
              <FilterTabs
                tabs={paymentFilterTabs}
                value={paymentStatusFilter}
                onChange={setPaymentStatusFilter}
              />
            </div>
          )}
        </div>
      }
      pagination={
        !error && documents.length > 0 ? (
          <OffsetPagination
            currentPage={currentPage}
            lastPage={lastPage}
            total={total}
            perPage={perPageActual}
            from={rangeFrom}
            to={rangeTo}
            onPageChange={setPage}
            onPerPageChange={(n) => { setPerPage(n); setPage(1) }}
          />
        ) : undefined
      }
    >
      {error ? (
        <div className={cn(tokens.alert.base, tokens.alert.error)}>
          {t('errors.loadingFailed')}
        </div>
      ) : (
        <DataTable
          columns={columns}
          data={documents}
          keyExtractor={(doc) => doc.id}
          isLoading={isLoading}
          emptyState={
            <div className="py-6">
              <EmptyState
                icon={<FileText className={cn('mx-auto h-12 w-12', textColors.disabled)} />}
                title={t('sales:documents.empty.title')}
                description={t('sales:documents.empty.description')}
              />
              <div className="mt-6 flex justify-center">
                <Button className="gap-2" onClick={() => { void navigate(addPath) }}>
                  <Plus className="h-4 w-4" />
                  {t('actions.add')} {entitySingular}
                </Button>
              </div>
            </div>
          }
        />
      )}
    </ListPageLayout>
  )
}
