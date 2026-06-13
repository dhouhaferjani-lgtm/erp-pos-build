/**
 * CreditNoteDetail Component
 * Displays detailed view of a credit note with print functionality
 */

import { useTranslation } from 'react-i18next'
import { Printer, FileText, X } from 'lucide-react'
import { useCurrency } from '@/hooks/useCurrency'
import type { CreditNote } from '@/types/creditNote'

interface CreditNoteDetailProps {
  creditNote: CreditNote
  onClose?: () => void
  onViewInvoice?: (invoiceId: string) => void
}

/**
 * Displays comprehensive credit note information
 *
 * Features:
 * - All credit note fields displayed
 * - Print functionality
 * - Link to source invoice
 * - Status badge
 * - Close button
 */
export function CreditNoteDetail({
  creditNote,
  onClose,
  onViewInvoice,
}: CreditNoteDetailProps) {
  const { t } = useTranslation(['sales', 'common'])
  const { decimals } = useCurrency()

  // Format amount to currency-aware decimals
  const formatAmount = (amount: string): string => {
    return parseFloat(amount).toFixed(decimals)
  }

  // Format date
  const formatDate = (date: string): string => {
    return date // Keep ISO format for now, can enhance with locale formatting later
  }

  // Get reason label
  const getReasonLabel = (reason: CreditNote['reason']): string => {
    return t(`sales:creditNotes.reasons.${reason}`)
  }

  // Handle print
  const handlePrint = () => {
    window.print()
  }

  // Handle view invoice
  const handleViewInvoice = () => {
    if (onViewInvoice) {
      onViewInvoice(creditNote.source_invoice_id)
    }
  }

  return (
    <div className="rounded-lg border border-gray-200 bg-white shadow-sm">
      {/* Header */}
      <div className="flex items-center justify-between border-b border-gray-200 bg-gray-50 px-6 py-4">
        <h2 className="text-lg font-semibold text-gray-900">
          {t('sales:creditNotes.detail.title')}
        </h2>
        <div className="flex items-center gap-2">
          {/* Status Badge */}
          {creditNote.status === 'posted' ? (
            <span className="inline-flex rounded-full bg-green-100 px-3 py-1 text-sm font-medium text-green-800">
              {t('common:status.posted')}
            </span>
          ) : (
            <span className="inline-flex rounded-full bg-gray-100 px-3 py-1 text-sm font-medium text-gray-800">
              {t('common:status.draft')}
            </span>
          )}
        </div>
      </div>

      {/* Body */}
      <div className="space-y-6 p-6">
        {/* Credit Note Details Grid */}
        <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
          {/* Number */}
          <div>
            <label className="block text-sm font-medium text-gray-700">
              {t('sales:creditNotes.number')}
            </label>
            <p className="mt-1 text-base font-semibold text-gray-900">
              {creditNote.document_number}
            </p>
          </div>

          {/* Date */}
          <div>
            <label className="block text-sm font-medium text-gray-700">
              {t('sales:creditNotes.date')}
            </label>
            <p className="mt-1 text-base text-gray-900">
              {formatDate(creditNote.document_date)}
            </p>
          </div>

          {/* Source Invoice */}
          <div>
            <label className="block text-sm font-medium text-gray-700">
              {t('sales:creditNotes.sourceInvoice')}
            </label>
            <p className="mt-1 text-base text-blue-600 hover:text-blue-800">
              {creditNote.source_invoice_number}
            </p>
          </div>

          {/* Amount */}
          <div>
            <label className="block text-sm font-medium text-gray-700">
              {t('sales:creditNotes.amount')}
            </label>
            <p className="mt-1 text-lg font-semibold text-gray-900">
              {formatAmount(creditNote.total)}
            </p>
          </div>

          {/* Reason */}
          <div>
            <label className="block text-sm font-medium text-gray-700">
              {t('sales:creditNotes.reasonLabel')}
            </label>
            <p className="mt-1 text-base text-gray-900">
              {getReasonLabel(creditNote.reason)}
            </p>
          </div>

          {/* Status */}
          <div>
            <label className="block text-sm font-medium text-gray-700">
              {t('sales:creditNotes.statusLabel')}
            </label>
            <p className="mt-1 text-base text-gray-900">
              {creditNote.status === 'posted'
                ? t('common:status.posted')
                : t('common:status.draft')}
            </p>
          </div>

          {/* Created At */}
          <div>
            <label className="block text-sm font-medium text-gray-700">
              {t('sales:creditNotes.createdAt')}
            </label>
            <p className="mt-1 text-sm text-gray-700">
              {formatDate(creditNote.created_at)}
            </p>
          </div>
        </div>

        {/* Notes Section - Only show if notes exist */}
        {creditNote.notes && (
          <div>
            <label className="block text-sm font-medium text-gray-700">
              {t('sales:creditNotes.notes')}
            </label>
            <p className="mt-1 whitespace-pre-wrap text-base text-gray-900">
              {creditNote.notes}
            </p>
          </div>
        )}
      </div>

      {/* Footer - Action Buttons */}
      <div className="flex items-center justify-between border-t border-gray-200 bg-gray-50 px-6 py-4">
        <div className="flex items-center gap-3">
          {/* Print Button */}
          <button
            type="button"
            onClick={handlePrint}
            className="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
          >
            <Printer className="h-4 w-4" />
            {t('sales:creditNotes.print')}
          </button>

          {/* View Invoice Button */}
          {onViewInvoice && (
            <button
              type="button"
              onClick={handleViewInvoice}
              className="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
            >
              <FileText className="h-4 w-4" />
              {t('sales:creditNotes.viewInvoice')}
            </button>
          )}
        </div>

        {/* Close Button */}
        {onClose && (
          <button
            type="button"
            onClick={onClose}
            className="inline-flex items-center gap-2 rounded-md bg-gray-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2"
          >
            <X className="h-4 w-4" />
            {t('common:actions.close')}
          </button>
        )}
      </div>
    </div>
  )
}
