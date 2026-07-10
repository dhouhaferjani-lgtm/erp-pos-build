import { useState } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from 'react-i18next'
import { X } from 'lucide-react'
import { borderColors, textColors } from '@/lib/designTokens'
import { usePermissions } from '@/hooks/usePermissions'
import { PartnerPicker, type PartnerPickerValue } from '@/components/molecules/pickers'
import { useTransferVehicleOwnership } from '../../hooks/useTransferVehicleOwnership'
import type { OwnershipReason } from '../../types'
import { Button } from '@/components/atoms'

interface TransferOwnershipModalProps {
  vehicleId: string
  isOpen: boolean
  onClose: () => void
  onSuccess?: () => void
}

const REASONS: OwnershipReason[] = [
  'initial_registration',
  'purchase',
  'sale',
  'transfer',
  'trade_in',
  'fleet_assignment',
  'fleet_return',
  'other',
]

/** Type guard matching an OwnershipReason without a type assertion. */
function isOwnershipReason(value: string): value is OwnershipReason {
  return (REASONS as string[]).includes(value)
}

export function TransferOwnershipModal({
  vehicleId,
  isOpen,
  onClose,
  onSuccess,
}: TransferOwnershipModalProps) {
  const { t } = useTranslation(['vehicle-ownership', 'common'])
  const { hasPermission } = usePermissions()
  const canTransfer = hasPermission('vehicles.manage_ownership')

  const [newOwner, setNewOwner] = useState<PartnerPickerValue | null>(null)
  const [reason, setReason] = useState<OwnershipReason>('sale')
  const [notes, setNotes] = useState('')
  const [errorMsg, setErrorMsg] = useState<string | null>(null)
  const [validationError, setValidationError] = useState<string | null>(null)

  const mutation = useTransferVehicleOwnership(vehicleId)

  if (!isOpen || !canTransfer) {
    return null
  }

  const handleSubmit = (e: React.FormEvent<HTMLFormElement>): void => {
    e.preventDefault()
    setErrorMsg(null)
    setValidationError(null)
    if (newOwner === null) {
      setValidationError(t('ownership.newOwnerRequired', { defaultValue: 'Pick a new owner.' }))
      return
    }
    mutation.mutate(
      {
        new_owner_partner_id: newOwner.id,
        occurred_at: new Date().toISOString(),
        reason_code: reason,
        notes: notes.trim() === '' ? null : notes.trim(),
      },
      {
        onSuccess: () => {
          setNewOwner(null)
          setNotes('')
          onSuccess?.()
          onClose()
        },
        onError: () => {
          setErrorMsg(t('ownership.transferError'))
        },
      },
    )
  }

  return createPortal(
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/30"
      role="dialog"
      aria-modal="true"
    >
      <div className={`w-full max-w-md rounded-lg bg-white p-6 shadow-xl border ${borderColors.light}`}>
        <div className="flex items-center justify-between mb-4">
          <h2 className={`text-lg font-semibold ${textColors.primary}`}>
            {t('ownership.transfer')}
          </h2>
          <Button
            type="button"
            aria-label={t('common:close', { defaultValue: 'Close' })}
            onClick={onClose}
            className={textColors.tertiary}
          >
            <X className="h-5 w-5" />
          </Button>
        </div>

        <form onSubmit={handleSubmit} className="flex flex-col gap-3">
          <div>
            <PartnerPicker
              value={newOwner}
              onChange={setNewOwner}
              label={t('ownership.newOwner')}
              partnerType="customer"
              required
              testId="transfer-owner-picker"
            />
            {validationError !== null ? (
              <div className={`mt-1 text-sm ${textColors.error}`} role="alert">
                {validationError}
              </div>
            ) : null}
          </div>

          <label className="flex flex-col gap-1">
            <span className={`text-sm ${textColors.secondary}`}>{t('ownership.reason')}</span>
            <select
              value={reason}
              onChange={(e) => {
                const value = e.target.value
                if (isOwnershipReason(value)) {
                  setReason(value)
                }
              }}
              className={`rounded border px-2 py-1 text-sm ${borderColors.default}`}
            >
              {REASONS.map((r) => (
                <option key={r} value={r}>
                  {t(`ownershipReason.${r}`)}
                </option>
              ))}
            </select>
          </label>

          <label className="flex flex-col gap-1">
            <span className={`text-sm ${textColors.secondary}`}>{t('ownership.notes')}</span>
            <textarea
              value={notes}
              onChange={(e) => { setNotes(e.target.value) }}
              placeholder={t('ownership.notesPlaceholder')}
              rows={3}
              className={`rounded border px-2 py-1 text-sm ${borderColors.default}`}
            />
          </label>

          {errorMsg !== null ? (
            <div className={`text-sm ${textColors.error}`} role="alert">
              {errorMsg}
            </div>
          ) : null}

          <div className="flex items-center justify-end gap-2 mt-2">
            <Button
              type="button"
              onClick={onClose}
              className={`rounded border px-3 py-1 text-sm ${borderColors.default} ${textColors.secondary}`}
              disabled={mutation.isPending}
            >
              {t('ownership.cancel')}
            </Button>
            <Button size="sm"
              type="submit"
              disabled={mutation.isPending || newOwner === null}
            >
              {t('ownership.confirmTransfer')}
            </Button>
          </div>
        </form>
      </div>
    </div>,
    document.body,
  )
}
