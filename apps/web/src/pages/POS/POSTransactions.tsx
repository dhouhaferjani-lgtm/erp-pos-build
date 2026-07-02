import { useTranslation } from 'react-i18next'
import { AlertCircle } from 'lucide-react'
import { colors, textColors } from '@/lib/designTokens'

/**
 * Fiscal Phase 1 §14.2 — POS new-sale server-authoring retired.
 *
 * The web-POS new-sale UI flow (build cart, quick-checkout, advanced
 * payments, complete sale) is hidden as part of the §14.2 disposition.
 * Receipts are now device-authored and ingested via
 * `POST /api/v1/pos/sync/fiscal-events`. Backend routes
 * `POST /api/v1/pos/receipts`, `POST /api/v1/pos/receipts/{id}/payments`,
 * and `POST /api/v1/pos/orders/{id}/close` return HTTP 410 Gone with
 * `NEW_SALE_AUTHORING_RETIRED` for all callers.
 *
 * Read-only receipt browsing and PDF download remain functional via
 * other pages (e.g., POS shifts dashboard). Refund Phase 6 quarantined
 * the web-admin void/return surface (ReceiptSearchPage + ReturnItemsModal
 * — it 422'd on every submit); post-seal corrections happen on the
 * desktop POS refund flow. The web-POS device-authority parity is
 * tracked as a §18 open item.
 *
 * Once device-authority web parity lands, this page is the entry point
 * to rebuild — repopulate from cart/payment state, replace the retired
 * `createReceipt`/`processReceiptPayments`/`closeOrder` API calls with
 * the local fiscal-event authoring chain + sync-flush, and re-enable
 * the affordances.
 */
export function POSTransactions() {
  const { t } = useTranslation(['pos', 'common'])

  return (
    <div className={`flex items-center justify-center h-screen ${colors.neutral[50]}`}>
      <div className="text-center max-w-lg p-8">
        <AlertCircle className="h-16 w-16 text-amber-500 mx-auto mb-4" />
        <h2 className={`text-xl font-bold ${textColors.primary} mb-2`}>
          {t('pos:transactions.disposition.title', {
            defaultValue: 'Point of sale runs in the desktop app',
          })}
        </h2>
        <p className={textColors.tertiary}>
          {t('pos:transactions.disposition.body', {
            defaultValue:
              'Sales are now rung up in the IziPOS desktop checkout app. From the web you can browse receipts, process returns, and download PDFs from the shop-management screens.',
          })}
        </p>
      </div>
    </div>
  )
}
