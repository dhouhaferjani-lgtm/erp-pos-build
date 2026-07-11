/**
 * @deprecated This component is deprecated as of December 2024.
 * The split payment functionality has been merged into RecordPaymentModal,
 * which now supports multiple payment lines with individual confirm buttons
 * and excess allocation options (FIFO, due date, manual, or customer advance).
 *
 * Use RecordPaymentModal instead - it handles both single and split payments.
 */
import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { Modal, ModalHeader, ModalContent } from '../Modal'
import { SplitPaymentForm } from '../../../features/treasury/SplitPaymentForm'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

/**
 * @deprecated Use RecordPaymentModal instead
 */
export interface SplitPaymentModalProps {
  /**
   * Controls modal visibility
   */
  isOpen: boolean

  /**
   * Callback when modal should close
   */
  onClose: () => void

  /**
   * Callback after successful split payment creation
   */
  onSuccess?: () => void

  /**
   * Document ID to pay
   */
  documentId: string

  /**
   * Total amount to pay (balance_due of the document)
   */
  totalAmount: number

  /**
   * Currency code (e.g., 'EUR', 'USD', 'TND')
   */
  currency: string

  /**
   * Document reference for display (e.g., 'INV-2024-001')
   */
  documentReference?: string
}

/**
 * @deprecated Use RecordPaymentModal instead. This component will be removed in a future release.
 *
 * SplitPaymentModal - Modal for recording split payments
 *
 * Allows users to pay a document using multiple payment methods.
 * Wraps the SplitPaymentForm component in a modal dialog.
 *
 * @example
 * ```tsx
 * <SplitPaymentModal
 *   isOpen={showSplitPayment}
 *   onClose={() => setShowSplitPayment(false)}
 *   onSuccess={() => {
 *     queryClient.invalidateQueries(['document', documentId])
 *     toast.success('Split payment recorded')
 *   }}
 *   documentId={document.id}
 *   totalAmount={parseFloat(document.balance_due)}
 *   currency={document.currency}
 *   documentReference={document.document_number}
 * />
 * ```
 */
export function SplitPaymentModal({
  isOpen,
  onClose,
  onSuccess,
  documentId,
  totalAmount,
  currency,
  documentReference,
}: SplitPaymentModalProps) {
  const { t } = useTranslation(['treasury', 'common'])

  // Reset state when modal closes
  useEffect(() => {
    if (!isOpen) {
      // Any cleanup if needed
    }
  }, [isOpen])

  const handleSuccess = () => {
    onSuccess?.()
    onClose()
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="lg">
      <ModalHeader
        title={t('treasury:splitPayment.title', 'Split Payment')}
        onClose={onClose}
      />

      <ModalContent>
        {/* Document context info */}
        {documentReference && (
          <div className={`rounded-lg ${colorTokens.intent.primary.bgSubtle} p-4 mb-4`}>
            <div className={`flex items-center gap-2 text-sm ${colorTokens.intent.primary.textStronger}`}>
              <span className="font-medium">{t('treasury:payments.payingFor', 'Paying for')}:</span>
              <span>{documentReference}</span>
            </div>
          </div>
        )}

        <SplitPaymentForm
          documentId={documentId}
          totalAmount={totalAmount}
          currency={currency}
          onSuccess={handleSuccess}
          onCancel={onClose}
        />
      </ModalContent>
    </Modal>
  )
}
