import { useMemo } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import {
  FileText,
  ShoppingCart,
  Receipt,
  FileX,
  Truck,
  ClipboardList,
} from 'lucide-react'
import { api } from '../../../lib/api'
import { useCompanyStore } from '../../../stores/companyStore'
import { formatCurrency } from '../../../lib/format'

interface DocumentLine {
  id: string
  product_id: string | null
  description: string
  quantity: string
  unit_price: string
  line_total: string
}

interface Document {
  id: string
  type: string
  status: string
  document_number: string
  document_date: string
  partner_id: string | null
  partner_name?: string
  total: string
  currency: string
  lines?: DocumentLine[]
}

interface DocumentsResponse {
  data: Document[]
  meta?: {
    total?: number
  }
}

interface ProductDocumentsTabProps {
  productId: string
}

const documentTypeConfig: Record<
  string,
  { label: string; color: string; icon: typeof FileText; path: string }
> = {
  quote: {
    label: 'Quote',
    color: 'bg-blue-100 text-blue-800',
    icon: ClipboardList,
    path: '/sales/quotes',
  },
  sales_order: {
    label: 'Sales Order',
    color: 'bg-purple-100 text-purple-800',
    icon: ShoppingCart,
    path: '/sales/orders',
  },
  invoice: {
    label: 'Invoice',
    color: 'bg-green-100 text-green-800',
    icon: Receipt,
    path: '/sales/invoices',
  },
  purchase_order: {
    label: 'Purchase Order',
    color: 'bg-orange-100 text-orange-800',
    icon: Truck,
    path: '/purchases/orders',
  },
  credit_note: {
    label: 'Credit Note',
    color: 'bg-red-100 text-red-800',
    icon: FileX,
    path: '/sales/credit-notes',
  },
  delivery_note: {
    label: 'Delivery Note',
    color: 'bg-gray-100 text-gray-800',
    icon: Truck,
    path: '/sales/delivery-notes',
  },
}

const statusConfig: Record<string, { label: string; color: string }> = {
  draft: { label: 'Draft', color: 'bg-gray-100 text-gray-800' },
  confirmed: { label: 'Confirmed', color: 'bg-blue-100 text-blue-800' },
  posted: { label: 'Posted', color: 'bg-green-100 text-green-800' },
  cancelled: { label: 'Cancelled', color: 'bg-red-100 text-red-800' },
  received: { label: 'Received', color: 'bg-purple-100 text-purple-800' },
}

export function ProductDocumentsTab({ productId }: ProductDocumentsTabProps) {
  const { t } = useTranslation(['inventory', 'common'])
  const currentCompany = useCompanyStore((state) => state.getCurrentCompany())

  // Get company currency with fallback
  const companyCurrency = currentCompany?.currency ?? 'EUR'
  const companyLocale = currentCompany?.locale.replace('_', '-') ?? 'en-US'

  const { data, isLoading, error } = useQuery({
    queryKey: ['product-documents', productId],
    queryFn: async () => {
      const response = await api.get<DocumentsResponse>(
        `/documents?product_id=${productId}`
      )
      return response.data
    },
    enabled: !!productId,
  })

  // Calculate product-specific totals for each document
  const documentsWithProductTotals = useMemo(() => {
    const documents = data?.data ?? []
    return documents.map((doc) => {
      const productLines = (doc.lines ?? []).filter(
        (line) => line.product_id === productId
      )
      const productQuantity = productLines.reduce(
        (sum, line) => sum + parseFloat(line.quantity || '0'),
        0
      )
      const productLineTotal = productLines.reduce(
        (sum, line) => sum + parseFloat(line.line_total || '0'),
        0
      )
      return {
        ...doc,
        productQuantity,
        productLineTotal,
      }
    })
  }, [data?.data, productId])

  const documents = data?.data ?? []

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString(companyLocale, {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    })
  }

  const formatAmount = (amount: number, currency?: string) => {
    return formatCurrency(amount, {
      currency: currency ?? companyCurrency,
      locale: companyLocale,
    })
  }

  const getDocumentConfig = (type: string) => {
    return (
      documentTypeConfig[type] ?? {
        label: type,
        color: 'bg-gray-100 text-gray-800',
        icon: FileText,
        path: '/documents',
      }
    )
  }

  const getStatusConfig = (status: string) => {
    return (
      statusConfig[status] ?? {
        label: status,
        color: 'bg-gray-100 text-gray-800',
      }
    )
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className="text-gray-500">{t('common:status.loading')}</div>
      </div>
    )
  }

  if (error) {
    return (
      <div className="rounded-lg bg-red-50 p-4 text-red-700">
        {t('common:errors.loadingFailed')}
      </div>
    )
  }

  return (
    <div className="space-y-4">
      {/* Header */}
      <div>
        <h3 className="text-lg font-semibold text-gray-900">
          {t('products.financialTab.title')}
        </h3>
        <p className="text-sm text-gray-500">
          {t('products.financialTab.subtitle', { count: documents.length })}
        </p>
      </div>

      {/* Content */}
      {documents.length === 0 ? (
        <div className="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center">
          <FileText className="mx-auto h-12 w-12 text-gray-400" />
          <h3 className="mt-2 text-sm font-semibold text-gray-900">
            {t('products.financialTab.empty.title')}
          </h3>
          <p className="mt-1 text-sm text-gray-500">
            {t('products.financialTab.empty.description')}
          </p>
        </div>
      ) : (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('products.financialTab.columns.documentNumber')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('products.financialTab.columns.type')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('products.financialTab.columns.date')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('products.financialTab.columns.partner')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('products.financialTab.columns.status')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('products.financialTab.columns.quantity')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('products.financialTab.columns.lineTotal')}
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {documentsWithProductTotals.map((doc) => {
                const typeConfig = getDocumentConfig(doc.type)
                const statusCfg = getStatusConfig(doc.status)
                const TypeIcon = typeConfig.icon

                return (
                  <tr key={doc.id} className="hover:bg-gray-50">
                    <td className="whitespace-nowrap px-6 py-4">
                      <Link
                        to={`${typeConfig.path}/${doc.id}`}
                        className="font-medium text-blue-600 hover:text-blue-800 hover:underline"
                      >
                        {doc.document_number}
                      </Link>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4">
                      <div className="flex items-center gap-2">
                        <TypeIcon className="h-4 w-4 text-gray-500" />
                        <span
                          className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${typeConfig.color}`}
                        >
                          {typeConfig.label}
                        </span>
                      </div>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                      {formatDate(doc.document_date)}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm">
                      {doc.partner_id && doc.partner_name ? (
                        <Link
                          to={`/partners/${doc.partner_id}`}
                          className="text-gray-900 hover:text-blue-600"
                        >
                          {doc.partner_name}
                        </Link>
                      ) : (
                        <span className="text-gray-400">-</span>
                      )}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4">
                      <span
                        className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${statusCfg.color}`}
                      >
                        {statusCfg.label}
                      </span>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                      {doc.productQuantity > 0
                        ? doc.productQuantity.toFixed(2)
                        : '-'}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-end text-sm font-medium text-gray-900">
                      {doc.productLineTotal > 0
                        ? formatAmount(doc.productLineTotal, doc.currency)
                        : '-'}
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
