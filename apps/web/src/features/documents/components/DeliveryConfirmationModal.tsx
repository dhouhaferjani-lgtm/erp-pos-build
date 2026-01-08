import { AlertTriangle, Package, CheckCircle } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Modal, ModalHeader, ModalContent, ModalFooter } from '../../../components/organisms/Modal/Modal'
import { useCompanyStore } from '../../../stores/companyStore'
import { formatCurrency } from '../../../lib/format'

interface DraftDeliveryNote {
  id: string
  number: string
  total: string
  line_count: number
}

interface DeliveryConfirmationModalProps {
  isOpen: boolean
  onClose: () => void
  draftDeliveryNotes: DraftDeliveryNote[]
  onConfirmAndPost: () => void
  isLoading?: boolean
}

/**
 * Modal for confirming auto-created delivery notes before posting invoice.
 *
 * Shows list of draft delivery notes that need confirmation, with warnings
 * about fiscal implications. User can confirm all DNs and post invoice in
 * one atomic operation.
 */
export function DeliveryConfirmationModal({
  isOpen,
  onClose,
  draftDeliveryNotes,
  onConfirmAndPost,
  isLoading = false,
}: DeliveryConfirmationModalProps) {
  const { t } = useTranslation(['sales', 'common'])
  const currentCompany = useCompanyStore((state) => state.getCurrentCompany())

  const companyCurrency = currentCompany?.currency ?? 'EUR'
  const companyLocale = currentCompany?.locale?.replace('_', '-') ?? 'en-US'

  const formatAmount = (amount: string | number) => {
    const num = typeof amount === 'string' ? parseFloat(amount) : amount
    return formatCurrency(isNaN(num) ? 0 : num, {
      currency: companyCurrency,
      locale: companyLocale,
    })
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="lg">
      <ModalHeader title={t('sales:invoices.deliveryConfirmation.title')} onClose={onClose} />

      <ModalContent>
        {/* Warning Banner */}
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4">
          <div className="flex gap-3">
            <AlertTriangle className="h-5 w-5 flex-shrink-0 text-amber-600" />
            <div className="space-y-1">
              <p className="text-sm font-medium text-amber-900">
                {t('sales:invoices.deliveryConfirmation.warning')}
              </p>
              <p className="text-sm text-amber-700">
                {t('sales:invoices.deliveryConfirmation.warningDetails')}
              </p>
            </div>
          </div>
        </div>

        {/* Description */}
        <div className="text-sm text-gray-600">
          <p>{t('sales:invoices.deliveryConfirmation.description')}</p>
        </div>

        {/* Delivery Notes List */}
        <div className="space-y-3">
          <h3 className="text-sm font-medium text-gray-900">
            {t('sales:invoices.deliveryConfirmation.deliveryNotesTitle')}
          </h3>

          <div className="divide-y divide-gray-200 rounded-lg border border-gray-200">
            {draftDeliveryNotes.map((dn) => (
              <div key={dn.id} className="flex items-center justify-between p-4">
                <div className="flex items-center gap-3">
                  <div className="rounded-lg bg-blue-100 p-2">
                    <Package className="h-5 w-5 text-blue-600" />
                  </div>
                  <div>
                    <p className="font-medium text-gray-900">{dn.number}</p>
                    <p className="text-sm text-gray-500">
                      {t('sales:invoices.deliveryConfirmation.lineCount', {
                        count: dn.line_count,
                      })}
                    </p>
                  </div>
                </div>
                <div className="text-end">
                  <p className="font-medium text-gray-900">{formatAmount(dn.total)}</p>
                  <p className="text-sm text-gray-500">
                    {t('sales:invoices.deliveryConfirmation.statusDraft')}
                  </p>
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* What Will Happen */}
        <div className="rounded-lg bg-blue-50 p-4">
          <div className="flex gap-3">
            <CheckCircle className="h-5 w-5 flex-shrink-0 text-blue-600" />
            <div className="space-y-2">
              <p className="text-sm font-medium text-blue-900">
                {t('sales:invoices.deliveryConfirmation.whatHappensTitle')}
              </p>
              <ul className="space-y-1 text-sm text-blue-700">
                <li>• {t('sales:invoices.deliveryConfirmation.step1')}</li>
                <li>• {t('sales:invoices.deliveryConfirmation.step2')}</li>
                <li>• {t('sales:invoices.deliveryConfirmation.step3')}</li>
                <li>• {t('sales:invoices.deliveryConfirmation.step4')}</li>
              </ul>
            </div>
          </div>
        </div>
      </ModalContent>

      <ModalFooter>
        <button
          type="button"
          onClick={onClose}
          disabled={isLoading}
          className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
        >
          {t('common:cancel')}
        </button>
        <button
          type="button"
          onClick={onConfirmAndPost}
          disabled={isLoading}
          className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
        >
          {isLoading ? (
            <>
              <div className="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent" />
              {t('sales:invoices.deliveryConfirmation.confirming')}
            </>
          ) : (
            <>
              <CheckCircle className="h-4 w-4" />
              {t('sales:invoices.deliveryConfirmation.confirmAndPost')}
            </>
          )}
        </button>
      </ModalFooter>
    </Modal>
  )
}
