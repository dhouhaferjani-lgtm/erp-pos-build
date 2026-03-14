import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms/Modal/Modal'
import { MoneyInput } from '../atoms/MoneyInput'
import { POSButton } from '../atoms/POSButton'
import { recordCashDeposit, recordCashPayout } from '../api/shiftApi'
import { AlertCircle, TrendingUp, TrendingDown } from 'lucide-react'
import { toast } from 'sonner'

export interface CashOperationModalProps {
  isOpen: boolean
  onClose: () => void
  type: 'deposit' | 'payout'
  shiftId: string
  terminalCode: string
}

/**
 * CashOperationModal - Record cash deposits and payouts
 *
 * This modal allows cashiers to record:
 * - Cash Deposits: Adding cash to the drawer (e.g., starting with more bills)
 * - Cash Payouts: Removing cash from the drawer (e.g., paying for supplies, giving change to other registers)
 *
 * Both operations require:
 * - Amount (must be positive)
 * - Reason (text description)
 *
 * After recording, the shift balance is updated and queries are invalidated.
 *
 * @example
 * ```tsx
 * <CashOperationModal
 *   isOpen={activeModal === 'deposit'}
 *   onClose={() => setActiveModal(null)}
 *   type="deposit"
 *   shiftId={shift.id}
 *   terminalCode="POS01"
 * />
 * ```
 */
export function CashOperationModal({
  isOpen,
  onClose,
  type,
  shiftId,
  terminalCode,
}: CashOperationModalProps) {
  const { t } = useTranslation(['common'])
  const queryClient = useQueryClient()
  const [amount, setAmount] = useState('')
  const [reason, setReason] = useState('')
  const [validationErrors, setValidationErrors] = useState<{
    amount?: string
    reason?: string
  }>({})

  const isDeposit = type === 'deposit'

  const operationMutation = useMutation({
    mutationFn: (data: { shift_id: string; amount: string; reason: string }) =>
      isDeposit ? recordCashDeposit(data) : recordCashPayout(data),
    onSuccess: () => {
      const successKey = isDeposit
        ? 'common:pos.depositRecorded'
        : 'common:pos.payoutRecorded'
      toast.success(t(successKey))

      // Invalidate shift balance to reflect the change
      queryClient.invalidateQueries({ queryKey: ['pos', 'shift-balance', shiftId] })
      queryClient.invalidateQueries({ queryKey: ['pos', 'shift', terminalCode] })

      // Close modal and reset form
      handleClose()
    },
    onError: (error: Error) => {
      toast.error(error.message || t('common:pos.operationError'))
    },
  })

  const handleClose = () => {
    setAmount('')
    setReason('')
    setValidationErrors({})
    onClose()
  }

  const handleSubmit = () => {
    // Validate inputs
    const errors: { amount?: string; reason?: string } = {}

    if (!amount || amount.trim() === '') {
      errors.amount = t('common:validation.required')
    } else {
      const amountNum = parseFloat(amount)
      if (isNaN(amountNum)) {
        errors.amount = t('common:validation.invalidNumber')
      } else if (amountNum <= 0) {
        errors.amount = t('common:pos.amountMustBePositive')
      }
    }

    if (!reason || reason.trim() === '') {
      errors.reason = t('common:validation.required')
    } else if (reason.trim().length < 3) {
      errors.reason = t('common:validation.minLength', { min: 3 })
    }

    if (Object.keys(errors).length > 0) {
      setValidationErrors(errors)
      return
    }

    // Clear validation errors
    setValidationErrors({})

    // Submit
    operationMutation.mutate({
      shift_id: shiftId,
      amount,
      reason: reason.trim(),
    })
  }

  const handleAmountChange = (value: string) => {
    setAmount(value)
    // Clear amount validation error when user types
    if (validationErrors.amount) {
      const { amount: _amount, ...rest } = validationErrors
      void _amount
      setValidationErrors(rest)
    }
  }

  const handleReasonChange = (e: React.ChangeEvent<HTMLTextAreaElement>) => {
    setReason(e.target.value)
    // Clear reason validation error when user types
    if (validationErrors.reason) {
      const { reason: _reason, ...rest } = validationErrors
      void _reason
      setValidationErrors(rest)
    }
  }

  const modalTitle = isDeposit
    ? t('common:pos.cashDeposit')
    : t('common:pos.cashPayout')

  const modalIcon = isDeposit ? (
    <TrendingUp className="h-5 w-5 text-green-600" />
  ) : (
    <TrendingDown className="h-5 w-5 text-red-600" />
  )

  const infoText = isDeposit
    ? t('common:pos.depositInfo')
    : t('common:pos.payoutInfo')

  return (
    <Modal isOpen={isOpen} onClose={handleClose} title={modalTitle} size="md">
      <ModalContent>
        <div className="space-y-4">
          {/* Info Box */}
          <div
            className={`flex items-start gap-3 rounded-lg p-4 ${
              isDeposit ? 'bg-green-50' : 'bg-red-50'
            }`}
          >
            {modalIcon}
            <div className="flex-1">
              <p
                className={`text-sm font-medium ${
                  isDeposit ? 'text-green-900' : 'text-red-900'
                }`}
              >
                {infoText}
              </p>
            </div>
          </div>

          {/* Amount Input */}
          <MoneyInput
            label={t('common:pos.amount')}
            value={amount}
            onChange={handleAmountChange}
            placeholder="0.000"
            autoFocus
            error={validationErrors.amount}
            disabled={operationMutation.isPending}
          />

          {/* Reason Input */}
          <div className="space-y-1">
            <label className="text-sm font-medium text-gray-700">
              {t('common:pos.reason')} *
            </label>
            <textarea
              value={reason}
              onChange={handleReasonChange}
              placeholder={t('common:pos.reasonPlaceholder')}
              rows={3}
              disabled={operationMutation.isPending}
              className={`w-full rounded-lg border px-4 py-2 text-base focus:outline-none focus:ring-2 transition-colors duration-150 ${
                validationErrors.reason
                  ? 'border-red-500 focus:ring-red-500'
                  : 'border-gray-300 focus:border-blue-500 focus:ring-blue-500'
              } ${
                operationMutation.isPending
                  ? 'bg-gray-100 cursor-not-allowed opacity-50'
                  : ''
              }`}
            />
            {validationErrors.reason && (
              <span className="text-sm text-red-600">{validationErrors.reason}</span>
            )}
          </div>

          {/* Warning */}
          <div className="flex items-start gap-2 rounded-lg bg-yellow-50 border border-yellow-200 p-3">
            <AlertCircle className="h-4 w-4 text-yellow-600 mt-0.5 flex-shrink-0" />
            <p className="text-sm text-yellow-800">
              {t('common:pos.cashOperationWarning')}
            </p>
          </div>
        </div>
      </ModalContent>

      <ModalFooter>
        <POSButton
          variant="secondary"
          size="lg"
          onClick={handleClose}
          disabled={operationMutation.isPending}
        >
          {t('common:cancel')}
        </POSButton>
        <POSButton
          variant={isDeposit ? 'success' : 'danger'}
          size="lg"
          onClick={handleSubmit}
          disabled={
            operationMutation.isPending ||
            !amount ||
            !reason ||
            !!validationErrors.amount ||
            !!validationErrors.reason
          }
        >
          {operationMutation.isPending
            ? t('common:pos.recording')
            : isDeposit
            ? t('common:pos.recordDeposit')
            : t('common:pos.recordPayout')}
        </POSButton>
      </ModalFooter>
    </Modal>
  )
}
