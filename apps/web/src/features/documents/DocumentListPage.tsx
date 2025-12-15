import { useState, useMemo } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, FileText, Calendar } from 'lucide-react'
import { api } from '../../lib/api'
import { SearchInput } from '../../components/ui/SearchInput'
import { FilterTabs } from '../../components/ui/FilterTabs'

interface Document {
  id: string
  document_number: string
  type: 'quote' | 'order' | 'invoice' | 'credit_note' | 'delivery_note' | 'sales_order' | 'purchase_order'
  status: 'draft' | 'confirmed' | 'posted' | 'cancelled' | 'received'
  fiscal_category: 'NON_FISCAL' | 'FISCAL_RECEIPT' | 'TAX_INVOICE' | 'CREDIT_NOTE'
  fiscal_status: 'DRAFT' | 'SEALED' | 'VOIDED'
  is_sealed: boolean
  is_fiscal: boolean
  partner_id: string
  partner_name: string | null
  subtotal: string | null
  tax_amount: string | null
  total: string | null
  balance_due: string | null
  document_date: string
  due_date: string | null
  created_at: string
}

interface DocumentsResponse {
  data: Document[]
  meta?: { total: number }
}

export type DocumentType = 'quote' | 'sales_order' | 'invoice' | 'purchase_order' | 'delivery_note' | 'credit_note'

const typeColors: Record<string, string> = {
  quote: 'bg-yellow-100 text-yellow-800',
  order: 'bg-blue-100 text-blue-800',
  sales_order: 'bg-blue-100 text-blue-800',
  purchase_order: 'bg-purple-100 text-purple-800',
  invoice: 'bg-green-100 text-green-800',
  credit_note: 'bg-red-100 text-red-800',
  delivery_note: 'bg-purple-100 text-purple-800',
}

const typeLabels: Record<string, string> = {
  quote: 'Quote',
  order: 'Order',
  sales_order: 'Sales Order',
  purchase_order: 'Purchase Order',
  invoice: 'Invoice',
  credit_note: 'Credit Note',
  delivery_note: 'Delivery Note',
}

const statusColors: Record<Document['status'], string> = {
  draft: 'bg-gray-100 text-gray-800',
  confirmed: 'bg-blue-100 text-blue-800',
  posted: 'bg-green-100 text-green-800',
  received: 'bg-teal-100 text-teal-800',
  cancelled: 'bg-red-100 text-red-800',
}

const statusLabels: Record<Document['status'], string> = {
  draft: 'Draft',
  confirmed: 'Confirmed',
  posted: 'Posted',
  received: 'Received',
  cancelled: 'Cancelled',
}

// Map document types to navigation paths
const documentTypeToPath: Record<DocumentType, string> = {
  quote: '/sales/quotes',
  sales_order: '/sales/orders',
  invoice: '/sales/invoices',
  purchase_order: '/purchases/orders',
  delivery_note: '/inventory/delivery-notes',
  credit_note: '/sales/credit-notes',
}

const documentTypeToTitle: Record<DocumentType, string> = {
  quote: 'Quotes',
  sales_order: 'Sales Orders',
  invoice: 'Invoices',
  purchase_order: 'Purchase Orders',
  delivery_note: 'Delivery Notes',
  credit_note: 'Credit Notes',
}

// Map document types to their API endpoints
const documentTypeToApiEndpoint: Record<DocumentType, string> = {
  quote: '/quotes',
  sales_order: '/orders',
  invoice: '/invoices',
  purchase_order: '/purchase-orders',
  delivery_note: '/delivery-notes',
  credit_note: '/credit-notes',
}

function getDocumentTypeFromPath(pathname: string): DocumentType | undefined {
  if (pathname.includes('/sales/quotes')) return 'quote'
  if (pathname.includes('/sales/orders')) return 'sales_order'
  if (pathname.includes('/sales/invoices')) return 'invoice'
  if (pathname.includes('/purchases/orders')) return 'purchase_order'
  if (pathname.includes('/inventory/delivery-notes')) return 'delivery_note'
  if (pathname.includes('/sales/credit-notes')) return 'credit_note'
  return undefined
}

type StatusFilter = 'all' | 'draft' | 'confirmed' | 'posted' | 'received' | 'cancelled'

interface DocumentListPageProps {
  documentType?: DocumentType
}

export function DocumentListPage({ documentType }: DocumentListPageProps) {
  const { t } = useTranslation()
  const location = useLocation()
  const [searchQuery, setSearchQuery] = useState('')
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all')

  // Determine the document type from props or URL path
  const effectiveType = documentType ?? getDocumentTypeFromPath(location.pathname)

  const basePath = effectiveType ? documentTypeToPath[effectiveType] : '/documents'
  const apiEndpoint = effectiveType ? documentTypeToApiEndpoint[effectiveType] : '/documents'
  const pageTitle = effectiveType ? documentTypeToTitle[effectiveType] : 'Documents'

  const { data, isLoading, error } = useQuery({
    queryKey: ['documents', effectiveType, searchQuery, statusFilter],
    queryFn: async () => {
      const params = new URLSearchParams()
      if (searchQuery) params.append('search', searchQuery)
      if (statusFilter !== 'all') params.append('status', statusFilter)
      const queryString = params.toString()
      const response = await api.get<DocumentsResponse>(`${apiEndpoint}${queryString ? `?${queryString}` : ''}`)
      return response.data
    },
    enabled: apiEndpoint !== '/documents',
  })

  const documents = data?.data ?? []
  const total = data?.meta?.total ?? documents.length

  // Filter tabs configuration - show received only for purchase orders
  const filterTabs = useMemo(() => {
    const tabs = [
      { value: 'all' as StatusFilter, label: t('filters.all'), count: total },
      { value: 'draft' as StatusFilter, label: statusLabels.draft },
      { value: 'confirmed' as StatusFilter, label: statusLabels.confirmed },
      { value: 'posted' as StatusFilter, label: statusLabels.posted },
    ]
    // Add received filter for purchase orders
    if (effectiveType === 'purchase_order') {
      tabs.push({ value: 'received' as StatusFilter, label: statusLabels.received })
    }
    tabs.push({ value: 'cancelled' as StatusFilter, label: statusLabels.cancelled })
    return tabs
  }, [t, total, effectiveType])

  const formatCurrency = (amount: string | number) => {
    const num = typeof amount === 'string' ? parseFloat(amount) : amount
    if (isNaN(num)) return '$0.00'
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: 'USD',
    }).format(num)
  }

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
      return { label: 'Paid', color: 'bg-green-100 text-green-800', key: 'paid' }
    }

    // Check if overdue
    if (doc.due_date) {
      const dueDate = new Date(doc.due_date)
      const today = new Date()
      today.setHours(0, 0, 0, 0)
      dueDate.setHours(0, 0, 0, 0)

      if (dueDate < today && balanceDue > 0) {
        return { label: 'Overdue', color: 'bg-red-100 text-red-800', key: 'overdue' }
      }
    }

    // Partially paid
    if (balanceDue > 0 && balanceDue < total) {
      return { label: 'Partial', color: 'bg-yellow-100 text-yellow-800', key: 'partial' }
    }

    // Unpaid
    return { label: 'Unpaid', color: 'bg-orange-100 text-orange-800', key: 'unpaid' }
  }

  const entityName = pageTitle.endsWith('s') ? pageTitle.slice(0, -1) : pageTitle

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">{pageTitle}</h1>
          <p className="text-gray-500">
            {total} {total === 1 ? entityName.toLowerCase() : `${entityName.toLowerCase()}s`} total
          </p>
        </div>
        <Link
          to={`${basePath}/new`}
          className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
        >
          <Plus className="h-4 w-4" />
          {t('actions.add')} {entityName}
        </Link>
      </div>

      {/* Filters */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <FilterTabs tabs={filterTabs} value={statusFilter} onChange={setStatusFilter} />
        <SearchInput
          value={searchQuery}
          onChange={setSearchQuery}
          placeholder={`${t('actions.search')} ${entityName.toLowerCase()}s...`}
          className="w-full sm:w-72"
        />
      </div>

      {/* Content */}
      {isLoading ? (
        <div className="flex items-center justify-center py-12">
          <div className="text-gray-500">Loading...</div>
        </div>
      ) : error ? (
        <div className="rounded-lg bg-red-50 p-4 text-red-700">
          Error loading documents. Please try again.
        </div>
      ) : documents.length === 0 ? (
        <div className="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center">
          <FileText className="mx-auto h-12 w-12 text-gray-400" />
          <h3 className="mt-2 text-sm font-semibold text-gray-900">No {entityName.toLowerCase()}s</h3>
          <p className="mt-1 text-sm text-gray-500">
            Get started by creating a new {entityName.toLowerCase()}.
          </p>
          <div className="mt-6">
            <Link
              to={`${basePath}/new`}
              className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
            >
              <Plus className="h-4 w-4" />
              {t('actions.add')} {entityName}
            </Link>
          </div>
        </div>
      ) : (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  Number
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  Type
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  Status
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  Partner
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  Date
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  Total
                </th>
                {/* Show Balance Due column only for invoices */}
                {effectiveType === 'invoice' && (
                  <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                    Balance Due
                  </th>
                )}
                <th className="relative px-6 py-3">
                  <span className="sr-only">Actions</span>
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
                        {typeLabels[doc.type]}
                      </span>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4">
                      <div className="flex flex-col gap-1">
                        <span
                          className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${statusColors[doc.status]}`}
                        >
                          {statusLabels[doc.status]}
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
                          {doc.partner_name ?? 'Unknown Partner'}
                        </Link>
                      ) : (
                        <span className="text-gray-500">{doc.partner_name ?? 'Unknown Partner'}</span>
                      )}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                      <div className="flex items-center gap-1">
                        <Calendar className="h-3.5 w-3.5" />
                        {new Date(doc.document_date).toLocaleDateString()}
                      </div>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-end text-sm font-medium text-gray-900">
                      {formatCurrency(doc.total ?? 0)}
                    </td>
                    {/* Balance Due column for invoices */}
                    {effectiveType === 'invoice' && (
                      <td className="whitespace-nowrap px-6 py-4 text-end text-sm font-medium">
                        <span className={paymentStatus?.key === 'overdue' ? 'text-red-600 font-semibold' : 'text-gray-900'}>
                          {formatCurrency(doc.balance_due ?? doc.total ?? 0)}
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
