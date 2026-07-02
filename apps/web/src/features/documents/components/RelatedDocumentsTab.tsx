import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import {
  FileText,
  FileCheck,
  FileOutput,
  FileInput,
  FileMinus,
  ChevronRight,
  Loader2,
  AlertCircle,
} from 'lucide-react'
import { useRelatedDocuments, type RelatedDocument } from '../hooks/useRelatedDocuments'
import { formatCurrency, formatDate } from '../../../lib/format'

interface RelatedDocumentsTabProps {
  documentId: string
  currency?: string
}

const documentTypeIcons: Record<string, React.ElementType> = {
  quote: FileText,
  sales_order: FileCheck,
  invoice: FileOutput,
  delivery_note: FileInput,
  credit_note: FileMinus,
  purchase_order: FileCheck,
}

const documentTypeRoutes: Record<string, string> = {
  quote: '/sales/quotes',
  sales_order: '/sales/orders',
  invoice: '/sales/invoices',
  delivery_note: '/inventory/delivery-notes',
  credit_note: '/sales/credit-notes',
  purchase_order: '/purchases/orders',
  return_note: '/sales/return-notes',
}

function getStatusColor(status: string): string {
  switch (status) {
    case 'draft':
      return 'bg-gray-100 text-gray-700'
    case 'confirmed':
      return 'bg-blue-100 text-blue-700'
    case 'posted':
      return 'bg-green-100 text-green-700'
    case 'cancelled':
      return 'bg-red-100 text-red-700'
    default:
      return 'bg-gray-100 text-gray-700'
  }
}

interface DocumentChainItemProps {
  document: RelatedDocument
  isCurrent?: boolean | undefined
  currency?: string | undefined
}

function DocumentChainItem({ document, isCurrent = false, currency }: DocumentChainItemProps) {
  const { t } = useTranslation(['sales'])
  const Icon = documentTypeIcons[document.type] || FileText
  const route = documentTypeRoutes[document.type] || '/documents'
  const documentNumberLabel = document.document_number ?? t('sales:documents.draftNumberPlaceholder')

  return (
    <Link
      to={`${route}/${document.id}`}
      className={`block p-4 rounded-lg border transition-colors ${
        isCurrent
          ? 'border-primary-500 bg-primary-50 ring-2 ring-primary-500'
          : 'border-gray-200 hover:border-primary-300 hover:bg-gray-50'
      }`}
    >
      <div className="flex items-start gap-3">
        <div
          className={`p-2 rounded-lg ${
            isCurrent ? 'bg-primary-100 text-primary-600' : 'bg-gray-100 text-gray-600'
          }`}
        >
          <Icon className="h-5 w-5" />
        </div>
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap">
            <span className="font-medium text-gray-900">
              {t(`sales:documents.types.${document.type}`, document.type)}
            </span>
            <span
              className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${getStatusColor(
                document.status
              )}`}
            >
              {t(`sales:documents.statuses.${document.status}`, document.status)}
            </span>
            {isCurrent && (
              <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-primary-100 text-primary-700">
                {t('sales:relatedDocuments.current')}
              </span>
            )}
          </div>
          <p className="text-sm text-gray-600 mt-1">{documentNumberLabel}</p>
          <div className="flex items-center gap-4 mt-2 text-sm text-gray-500">
            <span>{formatDate(document.document_date)}</span>
            <span className="font-medium text-gray-700">
              {formatCurrency(document.total, { currency: currency ?? document.currency })}
            </span>
          </div>
        </div>
      </div>
    </Link>
  )
}

export function RelatedDocumentsTab({ documentId, currency }: RelatedDocumentsTabProps) {
  const { t } = useTranslation(['sales', 'common'])
  const { data: chain, isLoading, error } = useRelatedDocuments(documentId)

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className="h-8 w-8 animate-spin text-primary-500" />
      </div>
    )
  }

  if (error) {
    return (
      <div className="flex flex-col items-center justify-center py-12 text-red-600">
        <AlertCircle className="h-8 w-8 mb-2" />
        <p>{t('common:error')}</p>
      </div>
    )
  }

  if (!chain) {
    return null
  }

  const hasAncestors = chain.ancestors.length > 0
  const hasDescendants = chain.descendants.length > 0
  const hasRelated = hasAncestors || hasDescendants

  return (
    <div className="space-y-6">
      <div>
        <h3 className="text-lg font-medium text-gray-900 mb-2">
          {t('sales:relatedDocuments.title')}
        </h3>
        <p className="text-sm text-gray-500">{t('sales:relatedDocuments.description')}</p>
      </div>

      {!hasRelated ? (
        <div className="text-center py-8 text-gray-500">
          <FileText className="h-12 w-12 mx-auto mb-3 text-gray-300" />
          <p>{t('sales:relatedDocuments.noRelated')}</p>
        </div>
      ) : (
        <div className="space-y-4">
          {/* Ancestors */}
          {hasAncestors && (
            <div className="space-y-2">
              <h4 className="text-sm font-medium text-gray-700 flex items-center gap-2">
                {t('sales:relatedDocuments.sourceDocuments')}
              </h4>
              <div className="space-y-2">
                {chain.ancestors.map((doc, index) => (
                  <div key={doc.id} className="flex items-center gap-2">
                    <DocumentChainItem document={doc} currency={currency} />
                    {index < chain.ancestors.length - 1 && (
                      <ChevronRight className="h-5 w-5 text-gray-400 flex-shrink-0" />
                    )}
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Arrow to current */}
          {hasAncestors && (
            <div className="flex justify-center">
              <ChevronRight className="h-6 w-6 text-gray-400 rotate-90" />
            </div>
          )}

          {/* Current document */}
          <div>
            <h4 className="text-sm font-medium text-gray-700 mb-2">
              {t('sales:relatedDocuments.currentDocument')}
            </h4>
            <DocumentChainItem document={chain.current} isCurrent currency={currency} />
          </div>

          {/* Arrow to descendants */}
          {hasDescendants && (
            <div className="flex justify-center">
              <ChevronRight className="h-6 w-6 text-gray-400 rotate-90" />
            </div>
          )}

          {/* Descendants */}
          {hasDescendants && (
            <div className="space-y-2">
              <h4 className="text-sm font-medium text-gray-700">
                {t('sales:relatedDocuments.derivedDocuments')}
              </h4>
              <div className="grid gap-2 sm:grid-cols-2">
                {chain.descendants.map((doc) => (
                  <DocumentChainItem key={doc.id} document={doc} currency={currency} />
                ))}
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  )
}
