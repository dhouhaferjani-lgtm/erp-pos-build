/**
 * Related Documents Panel Component
 * Visualizes the complete document chain and relationships for a given document
 */

import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import {
  FileText,
  Receipt,
  Truck,
  ClipboardList,
  CreditCard,
  ArrowRight,
  ChevronDown,
  ChevronUp,
  Package
} from 'lucide-react'
import { useState } from 'react'
import { api } from '@/lib/api'
import { formatCurrency } from '@/lib/format'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { colorClasses } from '@/lib/designTokens'

interface RelatedDocument {
  id: string
  type: 'quote' | 'sales_order' | 'delivery_note' | 'invoice' | 'credit_note' | 'return_note'
  document_number?: string
  number?: string
  document_date: string
  total?: string | number
  currency?: string
  status: string
  partner?: {
    id: string
    name: string
  }
}

interface RelatedDocumentsData {
  source_documents: RelatedDocument[]
  derived_documents: RelatedDocument[]
  credit_notes: RelatedDocument[]
  return_notes: RelatedDocument[]
  document_chain: RelatedDocument[]
}

interface RelatedDocumentsPanelProps {
  documentId: string
  className?: string
}

const documentTypeConfig = {
  quote: { icon: FileText, color: `${colorClasses.textPurple600}`, bg: `${colorClasses.bgPurple50}`, label: 'Quote' },
  sales_order: { icon: ClipboardList, color: `${colorClasses.textBlue600}`, bg: `${colorClasses.bgBlue50}`, label: 'Sales Order' },
  delivery_note: { icon: Truck, color: `${colorClasses.textGreen600}`, bg: `${colorClasses.bgGreen50}`, label: 'Delivery Note' },
  invoice: { icon: Receipt, color: `${colorClasses.textOrange600}`, bg: `${colorClasses.bgOrange50}`, label: 'Invoice' },
  credit_note: { icon: CreditCard, color: `${colorClasses.textRed600}`, bg: `${colorClasses.bgRed50}`, label: 'Credit Note' },
  return_note: { icon: Package, color: `${colorClasses.textYellow600}`, bg: `${colorClasses.bgYellow50}`, label: 'Return Note' },
}

export function RelatedDocumentsPanel({
  documentId,
  className = ''
}: RelatedDocumentsPanelProps) {
  const { t } = useTranslation(['sales', 'common'])
  const [isExpanded, setIsExpanded] = useState(true)
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const companyCurrency = useCompanyStore((state) => state.getCurrentCompany()?.currency ?? 'EUR')

  // Fetch related documents
  const { data: relatedData, isLoading } = useQuery({
    queryKey: tenantScopedKey(['related-documents', documentId]),
    queryFn: async () => {
      const response = await api.get<{ data: RelatedDocumentsData }>(`/documents/${documentId}/related`)
      return response.data.data
    },
    enabled: tenantId !== null && companyId !== null && !!documentId,
  })

  const getDocumentIcon = (type: string) => {
    const config = documentTypeConfig[type as keyof typeof documentTypeConfig]
    return config ? config.icon : FileText
  }

  const getDocumentColor = (type: string) => {
    const config = documentTypeConfig[type as keyof typeof documentTypeConfig]
    return config ? config.color : `${colorClasses.textGray600}`
  }

  const getDocumentBg = (type: string) => {
    const config = documentTypeConfig[type as keyof typeof documentTypeConfig]
    return config ? config.bg : `${colorClasses.bgGray50}`
  }

  const getDocumentLabel = (type: string) => {
    const config = documentTypeConfig[type as keyof typeof documentTypeConfig]
    return config ? t(`sales:documents.types.${type}`, config.label) : type
  }

  const getDocumentNumber = (doc: RelatedDocument) => {
    return doc.document_number || doc.number || doc.id
  }

  const getDocumentUrl = (doc: RelatedDocument) => {
    const baseUrls: Record<string, string> = {
      quote: '/sales/quotes',
      sales_order: '/sales/orders',
      delivery_note: '/sales/delivery-notes',
      invoice: '/sales/invoices',
      credit_note: '/sales/credit-notes',
      return_note: '/sales/return-notes',
      purchase_order: '/purchases/orders',
    }
    return `${baseUrls[doc.type] || '/sales/documents'}/${doc.id}`
  }

  const formatDocumentTotal = (amount: string | number | undefined, currency?: string) => {
    if (!amount) return ''
    return formatCurrency(amount, { currency: currency ?? companyCurrency })
  }

  const renderDocumentCard = (doc: RelatedDocument, isCurrent: boolean = false) => {
    const Icon = getDocumentIcon(doc.type)
    const color = getDocumentColor(doc.type)
    const bg = getDocumentBg(doc.type)

    return (
      <Link
        key={doc.id}
        to={getDocumentUrl(doc)}
        className={`flex items-center gap-3 rounded-lg border p-3 transition-all ${
          isCurrent
            ? `${colorClasses.borderBlue500} ${colorClasses.bgBlue50} ring-2 ${colorClasses.ringBlue500} ring-offset-1`
            : `${colorClasses.borderGray200} ${colorClasses.hoverBorderGray300} ${colorClasses.hoverBgGray50}`
        }`}
      >
        <div className={`flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full ${bg}`}>
          <Icon className={`h-5 w-5 ${color}`} />
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <span className={`text-sm font-medium ${colorClasses.textGray900}`}>{getDocumentNumber(doc)}</span>
            {isCurrent && (
              <span className={`rounded-full ${colorClasses.bgBlue600} px-2 py-0.5 text-xs font-medium text-white`}>
                {t('common:status.current')}
              </span>
            )}
          </div>
          <div className={`text-xs ${colorClasses.textGray500}`}>
            {getDocumentLabel(doc.type)} • {new Date(doc.document_date).toLocaleDateString()}
          </div>
          {doc.partner && (
            <div className={`text-xs ${colorClasses.textGray500} truncate`}>{doc.partner.name}</div>
          )}
          {doc.total && (
            <div className={`text-xs font-medium ${colorClasses.textGray700} mt-1`}>
              {formatDocumentTotal(doc.total, doc.currency)}
            </div>
          )}
        </div>
      </Link>
    )
  }

  const renderDocumentChain = () => {
    if (!relatedData?.document_chain || relatedData.document_chain.length === 0) {
      return null
    }

    return (
      <div className="mb-6">
        <h3 className={`mb-3 text-sm font-semibold ${colorClasses.textGray900}`}>
          {t('sales:relatedDocuments.documentChain')}
        </h3>
        <div className="flex items-center gap-2 overflow-x-auto pb-2">
          {relatedData.document_chain.map((doc, index) => {
            const Icon = getDocumentIcon(doc.type)
            const color = getDocumentColor(doc.type)
            const bg = getDocumentBg(doc.type)
            const isCurrent = doc.id === documentId

            return (
              <div key={doc.id} className="flex items-center gap-2">
                <Link
                  to={getDocumentUrl(doc)}
                  className={`flex flex-col items-center gap-1 rounded-lg border p-2 transition-all ${
                    isCurrent
                      ? `${colorClasses.borderBlue500} ${colorClasses.bgBlue50} ring-2 ${colorClasses.ringBlue500}`
                      : `${colorClasses.borderGray200} ${colorClasses.hoverBorderGray300} ${colorClasses.hoverBgGray50}`
                  }`}
                >
                  <div className={`flex h-8 w-8 items-center justify-center rounded-full ${bg}`}>
                    <Icon className={`h-4 w-4 ${color}`} />
                  </div>
                  <span className={`text-xs font-medium ${colorClasses.textGray900} max-w-[80px] truncate`}>
                    {getDocumentNumber(doc)}
                  </span>
                  <span className={`text-xs ${colorClasses.textGray500}`}>
                    {getDocumentLabel(doc.type)}
                  </span>
                </Link>
                {index < relatedData.document_chain.length - 1 && (
                  <ArrowRight className={`h-4 w-4 flex-shrink-0 ${colorClasses.textGray400}`} />
                )}
              </div>
            )
          })}
        </div>
      </div>
    )
  }

  const hasRelatedDocuments = relatedData && (
    relatedData.source_documents.length > 0 ||
    relatedData.derived_documents.length > 0 ||
    relatedData.credit_notes.length > 0 ||
    relatedData.return_notes.length > 0
  )

  if (isLoading) {
    return (
      <div className={`rounded-lg border ${colorClasses.borderGray200} bg-white p-6 ${className}`}>
        <div className="flex items-center justify-center py-8">
          <div className={`inline-block h-6 w-6 animate-spin rounded-full border-4 border-solid ${colorClasses.borderBlue600} border-e-transparent`}></div>
          <span className={`ms-3 text-sm ${colorClasses.textGray500}`}>{t('common:status.loading')}</span>
        </div>
      </div>
    )
  }

  if (!hasRelatedDocuments && (!relatedData?.document_chain || relatedData.document_chain.length === 0)) {
    return null // Don't show panel if no related documents
  }

  return (
    <div className={`rounded-lg border ${colorClasses.borderGray200} bg-white ${className}`}>
      {/* Header */}
      <button
        type="button"
        onClick={() => { setIsExpanded(!isExpanded); }}
        className={`flex w-full items-center justify-between p-4 text-start ${colorClasses.hoverBgGray50} transition-colors`}
      >
        <h2 className={`text-lg font-semibold ${colorClasses.textGray900}`}>
          {t('sales:relatedDocuments.title')}
        </h2>
        {isExpanded ? (
          <ChevronUp className={`h-5 w-5 ${colorClasses.textGray400}`} />
        ) : (
          <ChevronDown className={`h-5 w-5 ${colorClasses.textGray400}`} />
        )}
      </button>

      {/* Content */}
      {isExpanded && (
        <div className={`border-t ${colorClasses.borderGray200} p-4`}>
          {/* Document Chain */}
          {renderDocumentChain()}

          {/* Source Documents */}
          {relatedData?.source_documents && relatedData.source_documents.length > 0 && (
            <div className="mb-6">
              <h3 className={`mb-3 text-sm font-semibold ${colorClasses.textGray900}`}>
                {t('sales:relatedDocuments.sourceDocuments')}
              </h3>
              <div className="space-y-2">
                {relatedData.source_documents.map((doc) =>
                  renderDocumentCard(doc, doc.id === documentId)
                )}
              </div>
            </div>
          )}

          {/* Derived Documents */}
          {relatedData?.derived_documents && relatedData.derived_documents.length > 0 && (
            <div className="mb-6">
              <h3 className={`mb-3 text-sm font-semibold ${colorClasses.textGray900}`}>
                {t('sales:relatedDocuments.derivedDocuments')}
              </h3>
              <div className="space-y-2">
                {relatedData.derived_documents.map((doc) =>
                  renderDocumentCard(doc, doc.id === documentId)
                )}
              </div>
            </div>
          )}

          {/* Credit Notes */}
          {relatedData?.credit_notes && relatedData.credit_notes.length > 0 && (
            <div className="mb-6">
              <h3 className={`mb-3 text-sm font-semibold ${colorClasses.textGray900}`}>
                {t('sales:relatedDocuments.creditNotes')}
              </h3>
              <div className="space-y-2">
                {relatedData.credit_notes.map((doc) =>
                  renderDocumentCard(doc, doc.id === documentId)
                )}
              </div>
            </div>
          )}

          {/* Return Notes */}
          {relatedData?.return_notes && relatedData.return_notes.length > 0 && (
            <div>
              <h3 className={`mb-3 text-sm font-semibold ${colorClasses.textGray900}`}>
                {t('sales:relatedDocuments.returnNotes')}
              </h3>
              <div className="space-y-2">
                {relatedData.return_notes.map((doc) =>
                  renderDocumentCard(doc, doc.id === documentId)
                )}
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  )
}
