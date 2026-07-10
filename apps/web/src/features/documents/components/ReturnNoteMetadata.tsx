/**
 * ReturnNoteMetadata Component
 * Displays return-specific information for return notes
 */

import { useTranslation } from 'react-i18next'
import { Package, AlertCircle, CreditCard, FileText, Link2 } from 'lucide-react'
import { Link } from 'react-router-dom'
import type { ReturnReason, ReturnCondition, RefundMethod } from '@/types/returnNote'
import { colorClasses } from '@/lib/designTokens'

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
        return `${colorClasses.bgRed100} ${colorClasses.textRed800} ${colorClasses.borderRed200}`
      case 'wrong_item':
        return `${colorClasses.bgOrange100} ${colorClasses.textOrange800} ${colorClasses.borderOrange200}`
      case 'customer_regret':
        return `${colorClasses.bgBlue100} ${colorClasses.textBlue800} ${colorClasses.borderBlue200}`
      case 'damaged_in_transit':
        return `${colorClasses.bgRed100} ${colorClasses.textRed800} ${colorClasses.borderRed200}`
      case 'warranty':
        return `${colorClasses.bgPurple100} ${colorClasses.textPurple800} ${colorClasses.borderPurple200}`
      case 'exchange':
        return `${colorClasses.bgGreen100} ${colorClasses.textGreen800} ${colorClasses.borderGreen200}`
      case 'other':
      default:
        return `${colorClasses.bgGray100} ${colorClasses.textGray800} ${colorClasses.borderGray200}`
    }
  }

  // Get color for condition
  const getConditionColor = (condition: ReturnCondition): string => {
    switch (condition) {
      case 'unopened':
        return `${colorClasses.bgGreen100} ${colorClasses.textGreen800}`
      case 'used':
        return `${colorClasses.bgYellow100} ${colorClasses.textYellow800}`
      case 'damaged':
        return `${colorClasses.bgOrange100} ${colorClasses.textOrange800}`
      case 'unusable':
        return `${colorClasses.bgRed100} ${colorClasses.textRed800}`
      default:
        return `${colorClasses.bgGray100} ${colorClasses.textGray800}`
    }
  }

  return (
    <div className={`space-y-4 rounded-lg border ${colorClasses.borderGray200} bg-white p-6`}>
      <div className={`flex items-center gap-2 border-b ${colorClasses.borderGray200} pb-3`}>
        <Package className={`h-5 w-5 ${colorClasses.textOrange600}`} />
        <h3 className={`text-lg font-semibold ${colorClasses.textGray900}`}>
          {t('returnNotes.summary.title', 'Return Information')}
        </h3>
      </div>

      {/* Return Reason */}
      <div>
        <label className={`block text-sm font-medium ${colorClasses.textGray700} mb-2`}>
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
          <label className={`block text-sm font-medium ${colorClasses.textGray700} mb-2`}>
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
          <label className={`block text-sm font-medium ${colorClasses.textGray700} mb-2`}>
            {t('returnNotes.refundMethod.label')}
          </label>
          <div className="inline-flex items-center gap-2 text-sm">
            <CreditCard className={`h-4 w-4 ${colorClasses.textGray500}`} />
            <span className={`${colorClasses.textGray900}`}>
              {t(`returnNotes.refundMethod.${metadata.refund_method.replace(/_/g, '')}`, metadata.refund_method)}
            </span>
          </div>
        </div>
      )}

      {/* Source Documents */}
      {(metadata.source_delivery_note_id || metadata.source_invoice_id) && (
        <div className={`border-t ${colorClasses.borderGray200} pt-4`}>
          <label className={`block text-sm font-medium ${colorClasses.textGray700} mb-2`}>
            {t('returnNotes.sourceDeliveryNote', 'Source Document')}
          </label>
          <div className="space-y-2">
            {metadata.source_delivery_note_id && sourceDeliveryNoteNumber && (
              <Link
                to={`/inventory/delivery-notes/${metadata.source_delivery_note_id}`}
                className={`inline-flex items-center gap-2 rounded-md ${colorClasses.bgPurple50} px-3 py-2 text-sm font-medium ${colorClasses.textPurple700} ${colorClasses.hoverBgPurple100} transition-colors`}
              >
                <FileText className="h-4 w-4" />
                {sourceDeliveryNoteNumber}
              </Link>
            )}
            {metadata.source_invoice_id && sourceInvoiceNumber && (
              <Link
                to={`/sales/invoices/${metadata.source_invoice_id}`}
                className={`inline-flex items-center gap-2 rounded-md ${colorClasses.bgGreen50} px-3 py-2 text-sm font-medium ${colorClasses.textGreen700} ${colorClasses.hoverBgGreen100} transition-colors`}
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
        <div className={`border-t ${colorClasses.borderGray200} pt-4`}>
          <label className={`block text-sm font-medium ${colorClasses.textGray700} mb-2`}>
            {t('returnNotes.linkedCreditNote', 'Linked Credit Note')}
          </label>
          <Link
            to={`/sales/credit-notes/${metadata.linked_credit_note_id}`}
            className={`inline-flex items-center gap-2 rounded-md ${colorClasses.bgRed50} px-3 py-2 text-sm font-medium ${colorClasses.textRed700} ${colorClasses.hoverBgRed100} transition-colors`}
          >
            <Link2 className="h-4 w-4" />
            {linkedCreditNoteNumber}
          </Link>
        </div>
      )}

      {/* Notes */}
      {metadata.notes && (
        <div className={`border-t ${colorClasses.borderGray200} pt-4`}>
          <label className={`block text-sm font-medium ${colorClasses.textGray700} mb-2`}>
            {t('returnNotes.notes')}
          </label>
          <p className={`text-sm ${colorClasses.textGray600} whitespace-pre-wrap`}>{metadata.notes}</p>
        </div>
      )}
    </div>
  )
}
