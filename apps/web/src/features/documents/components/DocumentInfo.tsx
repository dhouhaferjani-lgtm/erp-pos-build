/**
 * DocumentInfo Component
 *
 * Displays document metadata card (issue date, due date, etc.)
 */

import { useTranslation } from 'react-i18next'
import { Calendar } from 'lucide-react'
import type { Document } from '../../../types/document'

export interface DocumentInfoProps {
  /** The document to display info for */
  document: Document
  /** Optional class name for the container */
  className?: string
}

export function DocumentInfo({ document, className = '' }: DocumentInfoProps) {
  const { t } = useTranslation(['sales'])

  return (
    <div
      className={`rounded-lg border border-gray-200 bg-white p-6 ${className}`}
    >
      <h2 className="mb-4 text-lg font-semibold text-gray-900">
        {t('documents.documentInfo')}
      </h2>
      <dl className="space-y-3">
        {/* Issue/Document Date */}
        <div className="flex items-start gap-3">
          <Calendar className="mt-0.5 h-5 w-5 text-gray-400" />
          <div>
            <dt className="text-sm font-medium text-gray-500">
              {t('documents.issueDate')}
            </dt>
            <dd className="text-gray-900">
              {new Date(document.document_date).toLocaleDateString()}
            </dd>
          </div>
        </div>

        {/* Due Date */}
        {document.due_date && (
          <div className="flex items-start gap-3">
            <Calendar className="mt-0.5 h-5 w-5 text-gray-400" />
            <div>
              <dt className="text-sm font-medium text-gray-500">
                {t('documents.dueDate')}
              </dt>
              <dd className="text-gray-900">
                {new Date(document.due_date).toLocaleDateString()}
              </dd>
            </div>
          </div>
        )}

        {/* Valid Until (for quotes) */}
        {document.valid_until && (
          <div className="flex items-start gap-3">
            <Calendar className="mt-0.5 h-5 w-5 text-gray-400" />
            <div>
              <dt className="text-sm font-medium text-gray-500">
                {t('quotes.validUntil', 'Valid Until')}
              </dt>
              <dd className="text-gray-900">
                {new Date(document.valid_until).toLocaleDateString()}
              </dd>
            </div>
          </div>
        )}

        {/* External Document Number (for purchase orders) */}
        {document.external_document_number && (
          <div>
            <dt className="text-sm font-medium text-gray-500">
              {t('purchaseOrders.externalReference', 'External Reference')}
            </dt>
            <dd className="text-gray-900">{document.external_document_number}</dd>
          </div>
        )}

        {/* External Document Date */}
        {document.external_document_date && (
          <div className="flex items-start gap-3">
            <Calendar className="mt-0.5 h-5 w-5 text-gray-400" />
            <div>
              <dt className="text-sm font-medium text-gray-500">
                {t('purchaseOrders.externalDate', 'External Date')}
              </dt>
              <dd className="text-gray-900">
                {new Date(document.external_document_date).toLocaleDateString()}
              </dd>
            </div>
          </div>
        )}
      </dl>
    </div>
  )
}
