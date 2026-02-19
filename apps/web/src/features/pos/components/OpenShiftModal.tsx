import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation } from '@tanstack/react-query'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms/Modal/Modal'
import { MoneyInput } from '../atoms/MoneyInput'
import { POSButton } from '../atoms/POSButton'
import { openShift, type OpenShiftData } from '../api/shiftApi'
import { DollarSign, AlertCircle } from 'lucide-react'
import { toast } from 'sonner'

export interface OpenShiftModalProps {
  isOpen: boolean
  onClose: () => void
  terminalId: string
  onSuccess: () => void
}

/**
 * OpenShiftModal - Prompt cashier to enter opening cash amount
 *
 * This modal appears when a cashier starts their shift. It requires
 * them to count and enter the starting cash amount in the drawer,
 * which is critical for end-of-shift reconciliation.
 *
 * Features:
 * - MoneyInput with validation (must be >= 0)
 * - Loading state during API call
 * - Error handling with toast notifications
 * - Cannot be dismissed without opening shift (onClose is informational only)
 *
 * @example
 * ```tsx
 * <OpenShiftModal
 *   isOpen={!hasActiveShift}
 *   onClose={() => toast.warning('You must open a shift to continue')}
 *   terminalId="POS01"
 *   onSuccess={() => queryClient.invalidateQueries(['pos', 'shift'])}
 * />
 * ```
 */
export function OpenShiftModal({
  isOpen,
  onClose,
  terminalId,
  onSuccess,
}: OpenShiftModalProps) {
  const { t } = useTranslation(['common'])
  const [openingCash, setOpeningCash] = useState('')
  const [validationError, setValidationError] = useState<string | null>(null)

  const openShiftMutation = useMutation({
    mutationFn: (data: OpenShiftData) => openShift(data),
    onSuccess: () => {
      toast.success(t('common:pos.shiftOpened'))
      onSuccess()
      onClose()
      // Reset form
      setOpeningCash('')
      setValidationError(null)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('common:pos.shiftOpenError'))
    },
  })

  const handleSubmit = () => {
    // Validate opening cash
    if (!openingCash || openingCash.trim() === '') {
      setValidationError(t('common:validation.required'))
      return
    }

    const amount = parseFloat(openingCash)
    if (isNaN(amount)) {
      setValidationError(t('common:validation.invalidNumber'))
      return
    }

    if (amount < 0) {
      setValidationError(t('common:pos.openingCashMustBePositive'))
      return
    }

    // Clear validation error
    setValidationError(null)

    // Submit
    openShiftMutation.mutate({
      terminal_code: terminalId,
      opening_cash: openingCash,
    })
  }

  const handleOpeningCashChange = (value: string) => {
    setOpeningCash(value)
    // Clear validation error when user types
    if (validationError) {
      setValidationError(null)
    }
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={t('common:pos.openShift')} size="md">
      <ModalContent>
        <div className="space-y-4">
          {/* Instructions */}
          <div className="flex items-start gap-3 rounded-lg bg-blue-50 p-4">
            <DollarSign className="h-5 w-5 text-blue-600 mt-0.5 flex-shrink-0" />
            <div className="flex-1">
              <p className="text-sm font-medium text-blue-900">
                {t('common:pos.enterOpeningCash')}
              </p>
              <p className="mt-1 text-sm text-blue-700">
                {t('common:pos.openingCashHelp')}
              </p>
            </div>
          </div>

          {/* Opening Cash Input */}
          <MoneyInput
            label={t('common:pos.openingCash')}
            value={openingCash}
            onChange={handleOpeningCashChange}
            placeholder="0.000"
            autoFocus
            error={validationError || undefined}
            disabled={openShiftMutation.isPending}
          />

          {/* Warning about not closing modal */}
          <div className="flex items-start gap-2 rounded-lg bg-yellow-50 border border-yellow-200 p-3">
            <AlertCircle className="h-4 w-4 text-yellow-600 mt-0.5 flex-shrink-0" />
            <p className="text-sm text-yellow-800">
              {t('common:pos.mustOpenShiftWarning')}
            </p>
          </div>
        </div>
      </ModalContent>

      <ModalFooter>
        <POSButton
          variant="secondary"
          size="lg"
          onClick={onClose}
          disabled={openShiftMutation.isPending}
        >
          {t('common:cancel')}
        </POSButton>
        <POSButton
          variant="success"
          size="lg"
          onClick={handleSubmit}
          disabled={
            openShiftMutation.isPending ||
            !openingCash ||
            openingCash.trim() === '' ||
            !!validationError
          }
        >
          {openShiftMutation.isPending
            ? t('common:pos.openingShift')
            : t('common:pos.startShift')}
        </POSButton>
      </ModalFooter>
    </Modal>
  )
}
