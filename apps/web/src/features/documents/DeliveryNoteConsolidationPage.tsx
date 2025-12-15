/**
 * Delivery Note Consolidation Page
 *
 * Tunisia Model: Creates a single invoice from multiple confirmed delivery notes.
 */

import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft } from 'lucide-react'
import { DeliveryNoteConsolidation } from './components'

export function DeliveryNoteConsolidationPage() {
  const { t } = useTranslation(['sales', 'common'])
  const navigate = useNavigate()

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <button
          type="button"
          onClick={() => navigate(-1)}
          className="rounded-lg p-2 hover:bg-gray-100"
          aria-label={t('common:actions.back')}
        >
          <ArrowLeft className="h-5 w-5 text-gray-500" />
        </button>
        <div>
          <h1 className="text-2xl font-bold text-gray-900">
            {t('sales:deliveryNotes.consolidation.title')}
          </h1>
          <p className="mt-1 text-sm text-gray-500">
            {t('sales:deliveryNotes.consolidation.description')}
          </p>
        </div>
      </div>

      {/* Main Content */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <DeliveryNoteConsolidation
          onSuccess={(invoiceId) => navigate(`/sales/invoices/${invoiceId}`)}
          onCancel={() => navigate('/inventory/delivery-notes')}
        />
      </div>
    </div>
  )
}
