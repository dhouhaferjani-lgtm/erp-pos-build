/**
 * CreditNoteDetail Component
 * Displays detailed view of a credit note with print functionality
 */

import { useTranslation } from 'react-i18next'
import { Printer, FileText, X } from 'lucide-react'
import { formatCurrency } from '@/lib/format'
import type { CreditNote } from '@/types/creditNote'
import { DocumentStatus } from '@/types/creditNote'
import { colorClasses, semanticColorTokens } from '@/lib/designTokens'
import { ProformaBanner } from './ProformaBanner'

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

  /**
   * The DOCUMENT's currency, formatted from the string (rule 19).
   *
   * This was `parseFloat(amount).toFixed(decimals)` against the COMPANY's scale:
   * a float on money, and — on a TND credit note viewed from a 2-decimal company —
   * a silently truncated millime on the one figure the customer is owed.
   */
  const formatAmount = (amount: string): string =>
    formatCurrency(amount, { currency: creditNote.currency })

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

  // C-F0w / SPEC §2.4 — this component owns the only in-browser document PRINT
  // surface (`window.print()` above), so it renders what the PDF renders.
  //
  // N-6 decided from `status` because that was the only signal the API gave it,
  // and said so: "if a credit note is ever POSTED without a seal, this view cannot
  // tell". `is_proforma` closes that hole — it is `ProformaOutputPolicy`'s own
  // answer, keyed on the FISCAL SEAL, computed server-side. Never re-derive it.
  const isCancelled = creditNote.status === DocumentStatus.CANCELLED
  const isProforma = creditNote.is_proforma === true && !isCancelled

  return (
    <div className={`rounded-lg border ${colorClasses.borderGray200} bg-white shadow-sm`}>
      {isCancelled && (
        <div
          className={`border-b px-6 py-3 text-sm ${colorClasses.borderRed200} ${colorClasses.bgRed50} ${colorClasses.textRed700}`}
        >
          <strong className="block font-semibold uppercase tracking-wide">
            {t('sales:creditNotes.postingMarker.cancelledTitle')}
          </strong>
          <span>{t('sales:creditNotes.postingMarker.cancelledDetail')}</span>
        </div>
      )}
      {isProforma && <ProformaBanner className="rounded-b-none border-x-0 border-t-0" />}
      {/* Header */}
      <div className={`flex items-center justify-between border-b ${colorClasses.borderGray200} ${colorClasses.bgGray50} px-6 py-4`}>
        <h2 className={`text-lg font-semibold ${colorClasses.textGray900}`}>
          {t('sales:creditNotes.detail.title')}
        </h2>
        {/*
          * No status badge on a proforma. A `posted`-but-never-sealed credit note
          * wearing a green POSTED chip directly under a banner that says the sale
          * has not been entered in the accounts contradicts itself in front of the
          * customer — the banner is the only claim this page makes about it.
          */}
        <div className="flex items-center gap-2">
          {!isProforma &&
            (creditNote.status === 'posted' ? (
              <span className={`inline-flex rounded-full ${colorClasses.bgGreen100} px-3 py-1 text-sm font-medium ${colorClasses.textGreen800}`}>
                {t('common:status.posted')}
              </span>
            ) : (
              <span className={`inline-flex rounded-full ${colorClasses.bgGray100} px-3 py-1 text-sm font-medium ${colorClasses.textGray800}`}>
                {t('common:status.draft')}
              </span>
            ))}
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

          {/* Status — absent on a proforma, for the reason the badge is. */}
          {!isProforma && (
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
          )}

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

        {/*
          * The closing sentence of `templates/credit_note.blade.php`, and gated the
          * same way (fix round r3, conventions F-C3): a PROFORMA credit note reduces
          * no balance, so promising that it does would contradict the banner above.
          */}
        {!isProforma && !isCancelled && (
          <p className={`text-sm ${semanticColorTokens.text.muted}`}>
            {t('sales:documents.creditNote.balanceNote')}
          </p>
        )}

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
