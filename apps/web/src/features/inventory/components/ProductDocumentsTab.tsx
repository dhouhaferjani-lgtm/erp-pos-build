import { useMemo, useState } from 'react'
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
import { useAuthStore } from '../../../stores/authStore'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { formatCurrency } from '../../../lib/format'
import { useCurrency } from '@/hooks/useCurrency'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { StatusBadge, type StatusTone } from '@/components/atoms/StatusBadge'
import { statusTone } from '@/components/atoms/StatusBadge/statusTone'
import { EntityLink } from '@/components/molecules/EntityLink'
import { OffsetPagination } from '@/components/ui/OffsetPagination'
import { documentRouteTypeFromSource } from '@/lib/entityRoutes'
import { DataTable } from '@/components/molecules/DataTable/DataTable'
import type { OffsetPaginationMeta } from '@/types/pagination'

interface DocumentLine {
  id: string
  product_id: string | null
  description: string
  quantity: string
  unit_price: string
  line_total: string
  landed_unit_cost: string | null
}

interface Document {
  id: string
  type: string
  status: string
  document_number: string | null
  document_date: string
  partner_id: string | null
  partner_name?: string
  total: string
  currency: string
  lines?: DocumentLine[]
}

interface DocumentsResponse {
  data: Document[]
  meta?: Partial<OffsetPaginationMeta>
}

interface ProductDocumentsTabProps {
  productId: string
}

const documentTypeConfig: Record<
  string,
  { label: string; tone: StatusTone; icon: typeof FileText }
> = {
  quote: {
    label: 'Quote',
    tone: 'info',
    icon: ClipboardList,
  },
  sales_order: {
    label: 'Sales Order',
    tone: 'info',
    icon: ShoppingCart,
  },
  invoice: {
    label: 'Invoice',
    tone: 'success',
    icon: Receipt,
  },
  purchase_order: {
    label: 'Purchase Order',
    tone: 'warning',
    icon: Truck,
  },
  credit_note: {
    label: 'Credit Note',
    tone: 'danger',
    icon: FileX,
  },
  delivery_note: {
    label: 'Delivery Note',
    tone: 'neutral',
    icon: Truck,
  },
}

const statusLabels: Record<string, string> = {
  draft: 'Draft',
  confirmed: 'Confirmed',
  posted: 'Posted',
  cancelled: 'Cancelled',
  received: 'Received',
}

// Document-status → tone overrides (statusTone defaults cover draft/cancelled).
const statusToneOverrides: Record<string, StatusTone> = {
  confirmed: 'info',
  posted: 'success',
  received: 'info',
}

export function ProductDocumentsTab({ productId }: ProductDocumentsTabProps) {
  const { t } = useTranslation(['inventory', 'common', 'sales'])
  const { decimals } = useCurrency()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const currentCompany = useCompanyStore((state) =>
    state.companies.find((company) => company.id === state.currentCompanyId) ?? null
  )
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)

  // Get company currency with fallback
  const companyCurrency = currentCompany?.currency ?? 'EUR'
  const companyLocale = currentCompany?.locale.replace('_', '-') ?? 'en-US'

  const { data, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['product-documents', productId, page, perPage]),
    queryFn: async () => {
      const params = new URLSearchParams({
        product_id: productId,
        page: String(page),
        per_page: String(perPage),
      })
      const response = await api.get<DocumentsResponse>(
        `/documents?${params.toString()}`
      )
      return response.data
    },
    enabled: !!productId && !!tenantId && !!companyId,
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
      // Get landed unit cost from the first product line (should be same for all lines of same product)
      const landedUnitCost = productLines[0]?.landed_unit_cost
        ? parseFloat(productLines[0].landed_unit_cost)
        : null

      return {
        ...doc,
        productQuantity,
        productLineTotal,
        landedUnitCost,
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
        tone: 'neutral' as StatusTone,
        icon: FileText,
        path: '/documents',
      }
    )
  }

  const getStatusLabel = (status: string) => statusLabels[status] ?? status
  const getDocumentNumberLabel = (documentNumber: string | null) =>
    documentNumber ?? t('sales:documents.draftNumberPlaceholder')

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className={textColors.tertiary}>{t('common:status.loading')}</div>
      </div>
    )
  }

  if (error) {
    return (
      <div className={`${tokens.alert.base} ${tokens.alert.error}`}>
        {t('common:errors.loadingFailed')}
      </div>
    )
  }

  return (
    <div className="space-y-4">
      {/* Header */}
      <div>
        <h3 className={`text-lg font-semibold ${textColors.primary}`}>
          {t('products.financialTab.title')}
        </h3>
        <p className={`text-sm ${textColors.tertiary}`}>
          {t('products.financialTab.subtitle', { count: documents.length })}
        </p>
      </div>

      {/* Content */}
      {documents.length === 0 ? (
        <div className={`rounded-lg border-2 border-dashed ${borderColors.default} p-12 text-center`}>
          <FileText className={`mx-auto h-12 w-12 ${textColors.disabled}`} />
          <h3 className={`mt-2 text-sm font-semibold ${textColors.primary}`}>
            {t('products.financialTab.empty.title')}
          </h3>
          <p className={`mt-1 text-sm ${textColors.tertiary}`}>
            {t('products.financialTab.empty.description')}
          </p>
        </div>
      ) : (
        <div className={`overflow-hidden rounded-lg border ${borderColors.light} bg-white`}>
          <DataTable className={`min-w-full divide-y ${borderColors.divideDefault}`}>
            <thead className={tokens.table.header}>
              <tr>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                  {t('products.financialTab.columns.documentNumber')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                  {t('products.financialTab.columns.type')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                  {t('products.financialTab.columns.date')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                  {t('products.financialTab.columns.partner')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                  {t('products.financialTab.columns.status')}
                </th>
                <th className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                  {t('products.financialTab.columns.quantity')}
                </th>
                <th className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                  {t('landedCost.unitCost')}
                </th>
                <th className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
                  {t('products.financialTab.columns.lineTotal')}
                </th>
              </tr>
            </thead>
            <tbody className={`divide-y ${borderColors.divideDefault} bg-white`}>
              {documentsWithProductTotals.map((doc) => {
                const typeConfig = getDocumentConfig(doc.type)
                const TypeIcon = typeConfig.icon
                const documentType = documentRouteTypeFromSource(doc.type)

                return (
                  <tr key={doc.id} className={tokens.table.rowHover}>
                    <td className="whitespace-nowrap px-6 py-4">
                      {documentType ? (
                        <EntityLink
                          type="document"
                          id={doc.id}
                          documentType={documentType}
                          label={getDocumentNumberLabel(doc.document_number)}
                          className="font-medium"
                        />
                      ) : (
                        <Link
                          to={`/documents/${doc.id}`}
                          className={`font-medium ${textColors.brand} hover:underline`}
                        >
                          {getDocumentNumberLabel(doc.document_number)}
                        </Link>
                      )}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4">
                      <div className="flex items-center gap-2">
                        <TypeIcon className={`h-4 w-4 ${textColors.tertiary}`} />
                        <StatusBadge tone={typeConfig.tone}>
                          {typeConfig.label}
                        </StatusBadge>
                      </div>
                    </td>
                    <td className={`whitespace-nowrap px-6 py-4 text-sm ${textColors.tertiary}`}>
                      {formatDate(doc.document_date)}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm">
                      {doc.partner_id && doc.partner_name ? (
                        <EntityLink
                          type="partner"
                          id={doc.partner_id}
                          partnerType={doc.type === 'purchase_order' ? 'supplier' : 'customer'}
                          label={doc.partner_name}
                          className="font-medium"
                        />
                      ) : (
                        <span className={textColors.disabled}>-</span>
                      )}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4">
                      <StatusBadge
                        tone={statusTone(doc.status, statusToneOverrides)}
                      >
                        {getStatusLabel(doc.status)}
                      </StatusBadge>
                    </td>
                    <td className={`whitespace-nowrap px-6 py-4 text-end text-sm tabular-nums ${textColors.primary}`}>
                      {doc.productQuantity > 0
                        ? doc.productQuantity.toFixed(decimals)
                        : '-'}
                    </td>
                    <td className={`whitespace-nowrap px-6 py-4 text-end text-sm tabular-nums ${textColors.primary}`}>
                      {doc.landedUnitCost !== null
                        ? formatAmount(doc.landedUnitCost, doc.currency)
                        : '-'}
                    </td>
                    <td className={`whitespace-nowrap px-6 py-4 text-end text-sm font-medium tabular-nums ${textColors.primary}`}>
                      {doc.productLineTotal > 0
                        ? formatAmount(doc.productLineTotal, doc.currency)
                        : '-'}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </DataTable>
          {data?.meta?.current_page && data.meta.last_page && data.meta.last_page > 1 && (
            <OffsetPagination
              currentPage={data.meta.current_page}
              lastPage={data.meta.last_page}
              total={data.meta.total ?? documents.length}
              perPage={data.meta.per_page ?? perPage}
              from={data.meta.from ?? null}
              to={data.meta.to ?? null}
              onPageChange={setPage}
              onPerPageChange={(nextPerPage) => {
                setPerPage(nextPerPage)
                setPage(1)
              }}
            />
          )}
        </div>
      )}
    </div>
  )
}
