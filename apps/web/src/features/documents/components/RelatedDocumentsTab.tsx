import { useTranslation } from 'react-i18next'
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
import { StatusBadge, type StatusTone } from '@/components/atoms/StatusBadge/StatusBadge'
import { statusTone } from '@/components/atoms/StatusBadge/statusTone'
import { EntityLink } from '@/components/molecules/EntityLink'
import { documentRouteTypeFromSource } from '@/lib/entityRoutes'
import { useRelatedDocuments, type RelatedDocument } from '../hooks/useRelatedDocuments'
import { formatCurrency, formatDate } from '../../../lib/format'
import { colorClasses } from '@/lib/designTokens'

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

const relatedDocumentTones: Record<string, StatusTone> = {
  confirmed: 'info',
  posted: 'success',
}

interface DocumentChainItemProps {
  document: RelatedDocument
  isCurrent?: boolean | undefined
  currency?: string | undefined
}

function DocumentChainItem({ document, isCurrent = false, currency }: DocumentChainItemProps) {
  const { t } = useTranslation(['sales'])
  const Icon = documentTypeIcons[document.type] || FileText
  const documentType = documentRouteTypeFromSource(document.type)
  const documentNumberLabel = document.document_number ?? t('sales:documents.draftNumberPlaceholder')
  const content = (
    <div className="flex items-start gap-3">
      <div
        className={`p-2 rounded-lg ${
          isCurrent ? 'bg-primary-100 text-primary-600' : `${colorClasses.bgGray100} ${colorClasses.textGray600}`
        }`}
      >
        <Icon className="h-5 w-5" />
      </div>
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2 flex-wrap">
          <span className={`font-medium ${colorClasses.textGray900}`}>
            {t(`sales:documents.types.${document.type}`, document.type)}
          </span>
          <StatusBadge tone={statusTone(document.status, relatedDocumentTones)}>
            {t(`sales:documents.statuses.${document.status}`, document.status)}
          </StatusBadge>
          {isCurrent && (
            <StatusBadge tone="info">
              {t('sales:relatedDocuments.current')}
            </StatusBadge>
          )}
        </div>
        <p className={`text-sm ${colorClasses.textGray600} mt-1`}>{documentNumberLabel}</p>
        <div className={`flex items-center gap-4 mt-2 text-sm ${colorClasses.textGray500}`}>
          <span>{formatDate(document.document_date)}</span>
          <span className={`font-medium ${colorClasses.textGray700}`}>
            {formatCurrency(document.total, { currency: currency ?? document.currency })}
          </span>
        </div>
      </div>
    </div>
  )

  const cardClassName = `block p-4 rounded-lg border transition-colors ${
    isCurrent
      ? 'border-primary-500 bg-primary-50 ring-2 ring-primary-500'
      : `${colorClasses.borderGray200} hover:border-primary-300 ${colorClasses.hoverBgGray50}`
  }`

  return documentType ? (
    <EntityLink
      type="document"
      id={document.id}
      documentType={documentType}
      label={content}
      className={cardClassName}
    />
  ) : (
    <span className={cardClassName}>{content}</span>
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
      <div className={`flex flex-col items-center justify-center py-12 ${colorClasses.textRed600}`}>
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
        <h3 className={`text-lg font-medium ${colorClasses.textGray900} mb-2`}>
          {t('sales:relatedDocuments.title')}
        </h3>
        <p className={`text-sm ${colorClasses.textGray500}`}>{t('sales:relatedDocuments.description')}</p>
      </div>

      {!hasRelated ? (
        <div className={`text-center py-8 ${colorClasses.textGray500}`}>
          <FileText className={`h-12 w-12 mx-auto mb-3 ${colorClasses.textGray300}`} />
          <p>{t('sales:relatedDocuments.noRelated')}</p>
        </div>
      ) : (
        <div className="space-y-4">
          {/* Ancestors */}
          {hasAncestors && (
            <div className="space-y-2">
              <h4 className={`text-sm font-medium ${colorClasses.textGray700} flex items-center gap-2`}>
                {t('sales:relatedDocuments.sourceDocuments')}
              </h4>
              <div className="space-y-2">
                {chain.ancestors.map((doc, index) => (
                  <div key={doc.id} className="flex items-center gap-2">
                    <DocumentChainItem document={doc} currency={currency} />
                    {index < chain.ancestors.length - 1 && (
                      <ChevronRight className={`h-5 w-5 ${colorClasses.textGray400} flex-shrink-0`} />
                    )}
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Arrow to current */}
          {hasAncestors && (
            <div className="flex justify-center">
              <ChevronRight className={`h-6 w-6 ${colorClasses.textGray400} rotate-90`} />
            </div>
          )}

          {/* Current document */}
          <div>
            <h4 className={`text-sm font-medium ${colorClasses.textGray700} mb-2`}>
              {t('sales:relatedDocuments.currentDocument')}
            </h4>
            <DocumentChainItem document={chain.current} isCurrent currency={currency} />
          </div>

          {/* Arrow to descendants */}
          {hasDescendants && (
            <div className="flex justify-center">
              <ChevronRight className={`h-6 w-6 ${colorClasses.textGray400} rotate-90`} />
            </div>
          )}

          {/* Descendants */}
          {hasDescendants && (
            <div className="space-y-2">
              <h4 className={`text-sm font-medium ${colorClasses.textGray700}`}>
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
