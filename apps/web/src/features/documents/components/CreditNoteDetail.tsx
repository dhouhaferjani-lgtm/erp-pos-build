/**
 * CreditNoteDetail Component
 * Displays detailed view of a credit note with print functionality
 */

import { useTranslation } from 'react-i18next'
import { Printer, FileText, X } from 'lucide-react'
import { useCurrency } from '@/hooks/useCurrency'
import type { CreditNote } from '@/types/creditNote'
import { DocumentStatus } from '@/types/creditNote'
import { colorClasses } from '@/lib/designTokens'

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

  // N-6 (fiscal gate r1 F-5) — this component owns the only in-browser document
  // PRINT surface (`window.print()` above), so the owner-ruled marker that the
  // server-side PDF carries has to exist here too: an unposted credit note
  // printed from this view showed VAT with nothing saying it was never booked.
  //
  // The FE decides from `status` because that is the only signal the API gives
  // it — `CreditNote` carries no `fiscal_hash` / `fiscal_status`. That is safe
  // for the case the blade got wrong: `CANCELLED` gets its OWN message, so a
  // sealed-then-cancelled document is never described as unsealed. Residual: if
  // a credit note is ever `POSTED` without a seal, this view cannot tell.
  const isCancelled = creditNote.status === DocumentStatus.CANCELLED
  // NOTE: the FE `DocumentStatus` union has no `PAID` member — a credit note is
  // money owed BY us and is never settled by a customer receipt (the N-6
  // classifier refuses allocating to one), so `POSTED` is the booked state here.
  const isBooked = creditNote.status === DocumentStatus.POSTED

  return (
    <div className={`rounded-lg border ${colorClasses.borderGray200} bg-white shadow-sm`}>
      {(isCancelled || !isBooked) && (
        <div
          className={`border-b px-6 py-3 text-sm ${
            isCancelled
              ? `${colorClasses.borderRed200} ${colorClasses.bgRed50} ${colorClasses.textRed700}`
              : `${colorClasses.borderAmber200} ${colorClasses.bgAmber50} ${colorClasses.textAmber700}`
          }`}
        >
          <strong className="block font-semibold uppercase tracking-wide">
            {isCancelled
              ? t('sales:creditNotes.postingMarker.cancelledTitle')
              : t('sales:creditNotes.postingMarker.title')}
          </strong>
          <span>
            {isCancelled
              ? t('sales:creditNotes.postingMarker.cancelledDetail')
              : t('sales:creditNotes.postingMarker.detail')}
          </span>
        </div>
      )}
      {/* Header */}
      <div className={`flex items-center justify-between border-b ${colorClasses.borderGray200} ${colorClasses.bgGray50} px-6 py-4`}>
        <h2 className={`text-lg font-semibold ${colorClasses.textGray900}`}>
          {t('sales:creditNotes.detail.title')}
        </h2>
        <div className="flex items-center gap-2">
          {/* Status Badge */}
          {creditNote.status === 'posted' ? (
            <span className={`inline-flex rounded-full ${colorClasses.bgGreen100} px-3 py-1 text-sm font-medium ${colorClasses.textGreen800}`}>
              {t('common:status.posted')}
            </span>
          ) : (
            <span className={`inline-flex rounded-full ${colorClasses.bgGray100} px-3 py-1 text-sm font-medium ${colorClasses.textGray800}`}>
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
            <label className={`block text-sm font-medium ${colorClasses.textGray700}`}>
              {t('sales:creditNotes.number')}
            </label>
            <p className={`mt-1 text-base font-semibold ${colorClasses.textGray900}`}>
              {creditNote.document_number}
            </p>
          </div>

          {/* Date */}
          <div>
            <label className={`block text-sm font-medium ${colorClasses.textGray700}`}>
              {t('sales:creditNotes.date')}
            </label>
            <p className={`mt-1 text-base ${colorClasses.textGray900}`}>
              {formatDate(creditNote.document_date)}
            </p>
          </div>

          {/* Source Invoice */}
          <div>
            <label className={`block text-sm font-medium ${colorClasses.textGray700}`}>
              {t('sales:creditNotes.sourceInvoice')}
            </label>
            <p className={`mt-1 text-base ${colorClasses.textBlue600} ${colorClasses.hoverTextBlue800}`}>
              {creditNote.source_invoice_number}
            </p>
          </div>

          {/* Amount */}
          <div>
            <label className={`block text-sm font-medium ${colorClasses.textGray700}`}>
              {t('sales:creditNotes.amount')}
            </label>
            <p className={`mt-1 text-lg font-semibold ${colorClasses.textGray900}`}>
              {formatAmount(creditNote.total)}
            </p>
          </div>

          {/* Reason */}
          <div>
            <label className={`block text-sm font-medium ${colorClasses.textGray700}`}>
              {t('sales:creditNotes.reasonLabel')}
            </label>
            <p className={`mt-1 text-base ${colorClasses.textGray900}`}>
              {getReasonLabel(creditNote.reason)}
            </p>
          </div>

          {/* Status */}
          <div>
            <label className={`block text-sm font-medium ${colorClasses.textGray700}`}>
              {t('sales:creditNotes.statusLabel')}
            </label>
            <p className={`mt-1 text-base ${colorClasses.textGray900}`}>
              {creditNote.status === 'posted'
                ? t('common:status.posted')
                : t('common:status.draft')}
            </p>
          </div>

          {/* Created At */}
          <div>
            <label className={`block text-sm font-medium ${colorClasses.textGray700}`}>
              {t('sales:creditNotes.createdAt')}
            </label>
            <p className={`mt-1 text-sm ${colorClasses.textGray700}`}>
              {formatDate(creditNote.created_at)}
            </p>
          </div>
        </div>

        {/* Notes Section - Only show if notes exist */}
        {creditNote.notes && (
          <div>
            <label className={`block text-sm font-medium ${colorClasses.textGray700}`}>
              {t('sales:creditNotes.notes')}
            </label>
            <p className={`mt-1 whitespace-pre-wrap text-base ${colorClasses.textGray900}`}>
              {creditNote.notes}
            </p>
          </div>
        )}
      </div>

      {/* Footer - Action Buttons */}
      <div className={`flex items-center justify-between border-t ${colorClasses.borderGray200} ${colorClasses.bgGray50} px-6 py-4`}>
        <div className="flex items-center gap-3">
          {/* Print Button */}
          <button
            type="button"
            onClick={handlePrint}
            className={`inline-flex items-center gap-2 rounded-md border ${colorClasses.borderGray300} bg-white px-4 py-2 text-sm font-medium ${colorClasses.textGray700} shadow-sm ${colorClasses.hoverBgGray50} focus:outline-none focus:ring-2 ${colorClasses.focusRingBlue500} focus:ring-offset-2`}
          >
            <Printer className="h-4 w-4" />
            {t('sales:creditNotes.print')}
          </button>

          {/* View Invoice Button */}
          {onViewInvoice && (
            <button
              type="button"
              onClick={handleViewInvoice}
              className={`inline-flex items-center gap-2 rounded-md border ${colorClasses.borderGray300} bg-white px-4 py-2 text-sm font-medium ${colorClasses.textGray700} shadow-sm ${colorClasses.hoverBgGray50} focus:outline-none focus:ring-2 ${colorClasses.focusRingBlue500} focus:ring-offset-2`}
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
            className={`inline-flex items-center gap-2 rounded-md ${colorClasses.bgGray600} px-4 py-2 text-sm font-medium text-white shadow-sm ${colorClasses.hoverBgGray700} focus:outline-none focus:ring-2 ${colorClasses.focusRingGray500} focus:ring-offset-2`}
          >
            <X className="h-4 w-4" />
            {t('common:actions.close')}
          </button>
        )}
      </div>
    </div>
  )
}
