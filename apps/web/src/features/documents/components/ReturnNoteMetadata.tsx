/**
 * ReturnNoteMetadata Component
 * Displays return-specific information for return notes
 */

import { useTranslation } from 'react-i18next'
import { Package, AlertCircle, CreditCard, FileText, Link2 } from 'lucide-react'
import { Link } from 'react-router-dom'
import type { ReturnReason, ReturnCondition, RefundMethod } from '@/types/returnNote'

interface ReturnNoteMetadataProps {
  metadata: {
    return_reason: ReturnReason
    return_condition?: ReturnCondition | null
    refund_method?: RefundMethod | null
    source_delivery_note_id?: string | null
    source_invoice_id?: string | null
    linked_credit_note_id?: string | null
    notes?: string | null
  }
  sourceDeliveryNoteNumber?: string | null
  sourceInvoiceNumber?: string | null
  linkedCreditNoteNumber?: string | null
}

/**
 * Component to display return note metadata in a detail view
 *
 * Features:
 * - Return reason badge with icon
 * - Return condition (if provided)
 * - Refund method (if provided)
 * - Links to source documents
 * - Link to credit note (if created)
 * - Return notes
 */
export function ReturnNoteMetadata({
  metadata,
  sourceDeliveryNoteNumber,
  sourceInvoiceNumber,
  linkedCreditNoteNumber,
}: ReturnNoteMetadataProps) {
  const { t } = useTranslation(['sales'])

  // Get color for return reason
  const getReasonColor = (reason: ReturnReason): string => {
    switch (reason) {
      case 'defective':
        return 'bg-red-100 text-red-800 border-red-200'
      case 'wrong_item':
        return 'bg-orange-100 text-orange-800 border-orange-200'
      case 'customer_regret':
        return 'bg-blue-100 text-blue-800 border-blue-200'
      case 'damaged_in_transit':
        return 'bg-red-100 text-red-800 border-red-200'
      case 'warranty':
        return 'bg-purple-100 text-purple-800 border-purple-200'
      case 'exchange':
        return 'bg-green-100 text-green-800 border-green-200'
      case 'other':
      default:
        return 'bg-gray-100 text-gray-800 border-gray-200'
    }
  }

  // Get color for condition
  const getConditionColor = (condition: ReturnCondition): string => {
    switch (condition) {
      case 'unopened':
        return 'bg-green-100 text-green-800'
      case 'used':
        return 'bg-yellow-100 text-yellow-800'
      case 'damaged':
        return 'bg-orange-100 text-orange-800'
      case 'unusable':
        return 'bg-red-100 text-red-800'
      default:
        return 'bg-gray-100 text-gray-800'
    }
  }

  return (
    <div className="space-y-4 rounded-lg border border-gray-200 bg-white p-6">
      <div className="flex items-center gap-2 border-b border-gray-200 pb-3">
        <Package className="h-5 w-5 text-orange-600" />
        <h3 className="text-lg font-semibold text-gray-900">
          {t('returnNotes.summary.title', 'Return Information')}
        </h3>
      </div>

      {/* Return Reason */}
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-2">
          {t('returnNotes.reason.label')}
        </label>
        <div className={`inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium ${getReasonColor(metadata.return_reason)}`}>
          <AlertCircle className="h-4 w-4" />
          {t(`returnNotes.reason.${metadata.return_reason.replace(/_/g, '')}`, metadata.return_reason)}
        </div>
      </div>

      {/* Return Condition */}
      {metadata.return_condition && (
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-2">
            {t('returnNotes.condition.label')}
          </label>
          <span className={`inline-flex rounded-full px-3 py-1 text-xs font-medium ${getConditionColor(metadata.return_condition)}`}>
            {t(`returnNotes.condition.${metadata.return_condition}`, metadata.return_condition)}
          </span>
        </div>
      )}

      {/* Refund Method */}
      {metadata.refund_method && (
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-2">
            {t('returnNotes.refundMethod.label')}
          </label>
          <div className="inline-flex items-center gap-2 text-sm">
            <CreditCard className="h-4 w-4 text-gray-500" />
            <span className="text-gray-900">
              {t(`returnNotes.refundMethod.${metadata.refund_method.replace(/_/g, '')}`, metadata.refund_method)}
            </span>
          </div>
        </div>
      )}

      {/* Source Documents */}
      {(metadata.source_delivery_note_id || metadata.source_invoice_id) && (
        <div className="border-t border-gray-200 pt-4">
          <label className="block text-sm font-medium text-gray-700 mb-2">
            {t('returnNotes.sourceDeliveryNote', 'Source Document')}
          </label>
          <div className="space-y-2">
            {metadata.source_delivery_note_id && sourceDeliveryNoteNumber && (
              <Link
                to={`/inventory/delivery-notes/${metadata.source_delivery_note_id}`}
                className="inline-flex items-center gap-2 rounded-md bg-purple-50 px-3 py-2 text-sm font-medium text-purple-700 hover:bg-purple-100 transition-colors"
              >
                <FileText className="h-4 w-4" />
                {sourceDeliveryNoteNumber}
              </Link>
            )}
            {metadata.source_invoice_id && sourceInvoiceNumber && (
              <Link
                to={`/sales/invoices/${metadata.source_invoice_id}`}
                className="inline-flex items-center gap-2 rounded-md bg-green-50 px-3 py-2 text-sm font-medium text-green-700 hover:bg-green-100 transition-colors"
              >
                <FileText className="h-4 w-4" />
                {sourceInvoiceNumber}
              </Link>
            )}
          </div>
        </div>
      )}

      {/* Linked Credit Note */}
      {metadata.linked_credit_note_id && linkedCreditNoteNumber && (
        <div className="border-t border-gray-200 pt-4">
          <label className="block text-sm font-medium text-gray-700 mb-2">
            {t('returnNotes.linkedCreditNote', 'Linked Credit Note')}
          </label>
          <Link
            to={`/sales/credit-notes/${metadata.linked_credit_note_id}`}
            className="inline-flex items-center gap-2 rounded-md bg-red-50 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-100 transition-colors"
          >
            <Link2 className="h-4 w-4" />
            {linkedCreditNoteNumber}
          </Link>
        </div>
      )}

      {/* Notes */}
      {metadata.notes && (
        <div className="border-t border-gray-200 pt-4">
          <label className="block text-sm font-medium text-gray-700 mb-2">
            {t('returnNotes.notes')}
          </label>
          <p className="text-sm text-gray-600 whitespace-pre-wrap">{metadata.notes}</p>
        </div>
      )}
    </div>
  )
}
