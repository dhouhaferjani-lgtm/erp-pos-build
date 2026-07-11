/**
 * Delivery Note Consolidation Page
 *
 * Tunisia Model: Creates a single invoice from multiple confirmed delivery notes.
 */

import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft } from 'lucide-react'
import { Button } from '@/components/atoms/Button/Button'
import { PageHeader } from '@/components/molecules/PageHeader/PageHeader'
import { DeliveryNoteConsolidation } from './components/DeliveryNoteConsolidation'
import { colorClasses } from '@/lib/designTokens'

export function DeliveryNoteConsolidationPage() {
  const { t } = useTranslation(['sales', 'common'])
  const navigate = useNavigate()

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('sales:deliveryNotes.consolidation.title')}
        subtitle={t('sales:deliveryNotes.consolidation.description')}
        actions={(
          <Button
            type="button"
            variant="secondary"
            onClick={() => { navigate(-1) }}
            className="gap-2"
          >
            <ArrowLeft className="h-4 w-4" />
            {t('common:actions.back')}
          </Button>
        )}
        className="mb-0"
      />

      {/* Main Content */}
      <div className={`rounded-lg border ${colorClasses.borderGray200} bg-white p-6`}>
        <DeliveryNoteConsolidation
          onSuccess={(invoiceId) => navigate(`/sales/invoices/${invoiceId}`)}
          onCancel={() => navigate('/inventory/delivery-notes')}
        />
      </div>
    </div>
  )
}
