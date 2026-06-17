import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms/Modal/Modal'
import { MoneyInput } from '../atoms/MoneyInput'
import { POSButton } from '../atoms/POSButton'
import { recordCashDeposit, recordCashPayout } from '../api/shiftApi'
import { AlertCircle, TrendingUp, TrendingDown } from 'lucide-react'
import { toast } from 'sonner'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { tokens, colors, textColors, borderColors, focusRing } from '@/lib/designTokens'
import { cn } from '@/lib/utils'

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
    onSuccess: async () => {
      const successKey = isDeposit
        ? 'common:pos.depositRecorded'
        : 'common:pos.payoutRecorded'
      toast.success(t(successKey))

      // Invalidate shift balance to reflect the change
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['pos', 'shift-balance', shiftId]) }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['pos', 'shift', terminalCode]) }),
      ])

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
    <TrendingUp className={cn('h-5 w-5', textColors.success)} />
  ) : (
    <TrendingDown className={cn('h-5 w-5', textColors.error)} />
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
            className={cn(
              'flex items-start gap-3 rounded-lg p-4',
              isDeposit ? tokens.alert.success : tokens.alert.error,
            )}
          >
            {modalIcon}
            <div className="flex-1">
              <p
                className={cn(
                  'text-sm font-medium',
                  isDeposit ? textColors.success : textColors.error,
                )}
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
            <label className={cn('text-sm font-medium', textColors.secondary)}>
              {t('common:pos.reason')} *
            </label>
            <textarea
              value={reason}
              onChange={handleReasonChange}
              placeholder={t('common:pos.reasonPlaceholder')}
              rows={3}
              disabled={operationMutation.isPending}
              className={cn(
                'w-full rounded-lg border px-4 py-2 text-base focus:outline-none focus:ring-2 transition-colors duration-150',
                validationErrors.reason
                  ? cn(borderColors.error, focusRing.error)
                  : cn(borderColors.default, focusRing.primary),
                operationMutation.isPending && cn(colors.neutral[100], 'cursor-not-allowed opacity-50'),
              )}
            />
            {validationErrors.reason && (
              <span className={cn('text-sm', textColors.error)}>{validationErrors.reason}</span>
            )}
          </div>

          {/* Warning */}
          <div className={cn('flex items-start gap-2 rounded-lg border p-3', tokens.alert.warning, borderColors.warning)}>
            <AlertCircle className={cn('h-4 w-4 mt-0.5 flex-shrink-0', textColors.warningDark)} />
            <p className={cn('text-sm', textColors.warning)}>
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
