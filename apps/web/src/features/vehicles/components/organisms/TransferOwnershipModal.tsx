import { useState } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from 'react-i18next'
import { X } from 'lucide-react'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { usePermissions } from '@/hooks/usePermissions'
import { useTransferVehicleOwnership } from '../../hooks/useTransferVehicleOwnership'
import type { OwnershipReason } from '../../types'

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

export function TransferOwnershipModal({
  vehicleId,
  isOpen,
  onClose,
  onSuccess,
}: TransferOwnershipModalProps) {
  const { t } = useTranslation(['vehicle-ownership', 'common'])
  const { hasPermission } = usePermissions()
  const canTransfer = hasPermission('vehicles.manage_ownership')

  const [newOwnerId, setNewOwnerId] = useState('')
  const [reason, setReason] = useState<OwnershipReason>('sale')
  const [notes, setNotes] = useState('')
  const [errorMsg, setErrorMsg] = useState<string | null>(null)

  const mutation = useTransferVehicleOwnership(vehicleId)

  if (!isOpen || !canTransfer) {
    return null
  }

  const handleSubmit = (e: React.FormEvent<HTMLFormElement>): void => {
    e.preventDefault()
    setErrorMsg(null)
    mutation.mutate(
      {
        new_owner_partner_id: newOwnerId,
        occurred_at: new Date().toISOString(),
        reason_code: reason,
        notes: notes.trim() === '' ? null : notes.trim(),
      },
      {
        onSuccess: () => {
          setNewOwnerId('')
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
          <button
            type="button"
            aria-label={t('common:close', { defaultValue: 'Close' })}
            onClick={onClose}
            className={textColors.tertiary}
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="flex flex-col gap-3">
          <label className="flex flex-col gap-1">
            <span className={`text-sm ${textColors.secondary}`}>{t('ownership.newOwner')}</span>
            <input
              type="text"
              required
              value={newOwnerId}
              onChange={(e) => { setNewOwnerId(e.target.value) }}
              placeholder="partner uuid"
              className={`rounded border px-2 py-1 text-sm ${borderColors.default}`}
            />
          </label>

          <label className="flex flex-col gap-1">
            <span className={`text-sm ${textColors.secondary}`}>{t('ownership.reason')}</span>
            <select
              value={reason}
              onChange={(e) => { setReason(e.target.value as OwnershipReason) }}
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
            <button
              type="button"
              onClick={onClose}
              className={`rounded border px-3 py-1 text-sm ${borderColors.default} ${textColors.secondary}`}
              disabled={mutation.isPending}
            >
              {t('ownership.cancel')}
            </button>
            <button
              type="submit"
              disabled={mutation.isPending || newOwnerId.trim() === ''}
              className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.sm}`}
            >
              {t('ownership.confirmTransfer')}
            </button>
          </div>
        </form>
      </div>
    </div>,
    document.body,
  )
}
