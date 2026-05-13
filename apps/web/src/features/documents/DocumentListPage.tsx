import { useState, useMemo } from 'react'
import { usePageTitle } from '../../hooks/usePageTitle'
import { Link, useLocation } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, FileText, Calendar } from 'lucide-react'
import { api } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { formatCurrency } from '../../lib/format'
import { SearchInput } from '../../components/ui/SearchInput'
import { FilterTabs } from '../../components/ui/FilterTabs'
import type { Document } from '../../types/document'

interface DocumentsResponse {
  data: Document[]
  meta?: { total: number }
}

export type DocumentType = 'quote' | 'sales_order' | 'invoice' | 'purchase_order' | 'delivery_note' | 'credit_note' | 'return_note'

const typeColors: Record<string, string> = {
  quote: 'bg-yellow-100 text-yellow-800',
  order: 'bg-blue-100 text-blue-800',
  sales_order: 'bg-blue-100 text-blue-800',
  purchase_order: 'bg-purple-100 text-purple-800',
  invoice: 'bg-green-100 text-green-800',
  credit_note: 'bg-red-100 text-red-800',
  delivery_note: 'bg-purple-100 text-purple-800',
  return_note: 'bg-orange-100 text-orange-800',
}

const statusColors: Record<Document['status'], string> = {
  draft: 'bg-gray-100 text-gray-800',
  confirmed: 'bg-blue-100 text-blue-800',
  posted: 'bg-green-100 text-green-800',
  received: 'bg-teal-100 text-teal-800',
  cancelled: 'bg-red-100 text-red-800',
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
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const currentCompany = useCompanyStore((state) => state.getCurrentCompany())
  const [searchQuery, setSearchQuery] = useState('')
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all')
  const [paymentStatusFilter, setPaymentStatusFilter] = useState<PaymentStatusFilter>('all')

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
    queryKey: tenantScopedKey(['documents', effectiveType, searchQuery, statusFilter]),
    queryFn: async () => {
      const params = new URLSearchParams()
      if (searchQuery) params.append('search', searchQuery)
      if (statusFilter !== 'all') params.append('status', statusFilter)
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

  const total = data?.meta?.total ?? documents.length

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
  const getPaymentStatus = (doc: Document): { label: string; color: string; key: string } | null => {
    // Only show payment status for posted invoices
    if (doc.type !== 'invoice' || doc.status !== 'posted') {
      return null
    }

    const balanceDue = parseFloat(doc.balance_due ?? doc.total ?? '0')
    const total = parseFloat(doc.total ?? '0')

    // Fully paid
    if (balanceDue === 0) {
      return { label: t('sales:documents.statuses.paid'), color: 'bg-green-100 text-green-800', key: 'paid' }
    }

    // Check if overdue
    if (doc.due_date) {
      const dueDate = new Date(doc.due_date)
      const today = new Date()
      today.setHours(0, 0, 0, 0)
      dueDate.setHours(0, 0, 0, 0)

      if (dueDate < today && balanceDue > 0) {
        return { label: t('sales:documents.statuses.overdue'), color: 'bg-red-100 text-red-800', key: 'overdue' }
      }
    }

    // Partially paid
    if (balanceDue > 0 && balanceDue < total) {
      return { label: t('sales:documents.statuses.partial'), color: 'bg-yellow-100 text-yellow-800', key: 'partial' }
    }

    // Unpaid
    return { label: t('sales:documents.statuses.unpaid'), color: 'bg-orange-100 text-orange-800', key: 'unpaid' }
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
          to={documentType === 'credit_note' ? `${basePath}/create` : `${basePath}/new`}
          className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
        >
          <Plus className="h-4 w-4" />
          {t('actions.add')} {entitySingular}
        </Link>
      </div>

      {/* Filters */}
      <div className="flex flex-col gap-4">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <FilterTabs tabs={filterTabs} value={statusFilter} onChange={setStatusFilter} />
          <SearchInput
            value={searchQuery}
            onChange={setSearchQuery}
            placeholder={`${t('actions.search')} ${pageTitle.toLowerCase()}...`}
            className="w-full sm:w-72"
          />
        </div>

        {/* Payment Status Filter (Invoices only) */}
        {effectiveType === 'invoice' && paymentFilterTabs.length > 0 && (
          <div className="border-t border-gray-200 pt-4">
            <label className="text-sm font-medium text-gray-700 mb-2 block">
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

      {/* Content */}
      {isLoading ? (
        <div className="flex items-center justify-center py-12">
          <div className="text-gray-500">{t('status.loading')}</div>
        </div>
      ) : error ? (
        <div className="rounded-lg bg-red-50 p-4 text-red-700">
          {t('errors.loadingFailed')}
        </div>
      ) : documents.length === 0 ? (
        <div className="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center">
          <FileText className="mx-auto h-12 w-12 text-gray-400" />
          <h3 className="mt-2 text-sm font-semibold text-gray-900">{t('sales:documents.empty.title')}</h3>
          <p className="mt-1 text-sm text-gray-500">
            {t('sales:documents.empty.description')}
          </p>
          <div className="mt-6">
            <Link
              to={documentType === 'credit_note' ? `${basePath}/create` : `${basePath}/new`}
              className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
            >
              <Plus className="h-4 w-4" />
              {t('actions.add')} {entitySingular}
            </Link>
          </div>
        </div>
      ) : (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('sales:documents.number')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('sales:documents.type')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('sales:documents.status')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('sales:documents.partner')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('sales:documents.date')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('sales:documents.total')}
                </th>
                {/* Show Balance Due column only for invoices */}
                {effectiveType === 'invoice' && (
                  <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('sales:documents.balanceDue')}
                  </th>
                )}
                <th className="relative px-6 py-3">
                  <span className="sr-only">{t('table.actionsColumn')}</span>
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {documents.map((doc) => {
                const paymentStatus = getPaymentStatus(doc)
                return (
                  <tr key={doc.id} className="hover:bg-gray-50">
                    <td className="whitespace-nowrap px-6 py-4">
                      <Link
                        to={`${basePath}/${doc.id}`}
                        className="font-medium text-gray-900 hover:text-blue-600"
                      >
                        {doc.document_number}
                      </Link>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4">
                      <span
                        className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${typeColors[doc.type]}`}
                      >
                        {getTypeLabel(doc.type)}
                      </span>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4">
                      <div className="flex flex-col gap-1">
                        <span
                          className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${statusColors[doc.status]}`}
                        >
                          {getStatusLabel(doc.status)}
                        </span>
                        {/* Payment status badge for posted invoices */}
                        {paymentStatus && (
                          <span
                            className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${paymentStatus.color}`}
                          >
                            {t(`sales:documents.statuses.${paymentStatus.key}`, paymentStatus.label)}
                          </span>
                        )}
                      </div>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                      {doc.partner_id ? (
                        <Link
                          to={doc.type === 'purchase_order'
                            ? `/purchases/suppliers/${doc.partner_id}`
                            : `/sales/customers/${doc.partner_id}`
                          }
                          className="text-blue-600 hover:text-blue-800 hover:underline"
                        >
                          {doc.partner_name ?? t('sales:partners.unknown')}
                        </Link>
                      ) : (
                        <span className="text-gray-500">{doc.partner_name ?? t('sales:partners.unknown')}</span>
                      )}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                      <div className="flex items-center gap-1">
                        <Calendar className="h-3.5 w-3.5" />
                        {new Date(doc.document_date).toLocaleDateString()}
                      </div>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-end text-sm font-medium text-gray-900">
                      {formatAmount(doc.total ?? 0)}
                    </td>
                    {/* Balance Due column for invoices */}
                    {effectiveType === 'invoice' && (
                      <td className="whitespace-nowrap px-6 py-4 text-end text-sm font-medium">
                        <span className={paymentStatus?.key === 'overdue' ? 'text-red-600 font-semibold' : 'text-gray-900'}>
                          {formatAmount(doc.balance_due ?? doc.total ?? 0)}
                        </span>
                      </td>
                    )}
                    <td className="whitespace-nowrap px-6 py-4 text-end text-sm">
                      <Link
                        to={`${basePath}/${doc.id}`}
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
      )}
    </div>
  )
}
