import { useTranslation } from 'react-i18next'
import { CheckCircle } from 'lucide-react'
import { Modal } from '@/components/organisms/Modal'
import { POSButton } from '../atoms/POSButton'
import { ReceiptPrintButton } from './ReceiptPrintButton'
import { textColors, colors, borderColors , semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { useCurrency } from '@/hooks/useCurrency'
import { cn } from '@/lib/utils'

export interface CheckoutSuccessDialogProps {
  isOpen: boolean
  onClose: () => void
  receiptId: string | null
  receiptNumber?: string | undefined
  total?: string | undefined
  changeDue?: number | undefined
  autoPrint?: boolean | undefined
  loyaltyPointsEarned?: number | undefined
}

/**
 * Checkout Success Dialog
 *
 * Displays transaction success message and receipt printing options.
 * Shown after successful checkout completion.
 */
export function CheckoutSuccessDialog({
  isOpen,
  onClose,
  receiptId,
  receiptNumber,
  total,
  changeDue,
  autoPrint = false,
  loyaltyPointsEarned,
}: CheckoutSuccessDialogProps) {
  const { t } = useTranslation(['pos', 'common'])
  const { decimals } = useCurrency()

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={t('pos:payment.transactionComplete')}
      size="md"
    >
      <div className="text-center space-y-6">
        <div className="flex items-center justify-center">
          <div className={cn('w-16 h-16 rounded-full flex items-center justify-center', colors.success[100])}>
            <CheckCircle className={cn('w-10 h-10', textColors.success)} />
          </div>
        </div>

        <div className="space-y-2">
          {receiptNumber && (
            <p className={cn('text-lg font-medium', textColors.primary)}>
              {t('pos:payment.receiptNumber', { number: receiptNumber })}
            </p>
          )}
          {total && (
            <p className={cn('text-lg font-semibold tabular-nums', textColors.brand)}>
              {t('pos:advancedPayments.total')}: {total}
            </p>
          )}
          {changeDue != null && changeDue > 0 && (
            <div className={cn('rounded-lg px-4 py-3 border', colors.success[50], borderColors.success)}>
              <p className={cn('text-lg font-bold tabular-nums', textColors.success)}>
                {t('pos:payment.changeDue', { amount: changeDue.toFixed(decimals) })}
              </p>
            </div>
          )}
          {loyaltyPointsEarned != null && loyaltyPointsEarned > 0 && (
            <p className={`text-sm font-medium ${colorTokens.intent.caution.text}`}>
              {t('pos:loyalty.earning.earned', { points: loyaltyPointsEarned })}
            </p>
          )}
        </div>

        <div className="space-y-4">
          {receiptId && (
            <ReceiptPrintButton
              receiptId={receiptId}
              showDownload={true}
              autoPrint={autoPrint}
              onPrintComplete={() => {}}
            />
          )}

          <POSButton
            onClick={onClose}
            variant="secondary"
            size="lg"
            fullWidth
          >
            {t('common:actions.close')}
          </POSButton>
        </div>
      </div>
    </Modal>
  )
}
