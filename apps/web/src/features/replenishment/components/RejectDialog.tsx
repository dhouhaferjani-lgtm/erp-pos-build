import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { RequirePermission } from '@/components/auth'
import { Button } from '@/components/atoms/Button/Button'
import { Modal } from '@/components/organisms/Modal/Modal'
import { getErrorMessage } from '@/lib/api'
import { tokens } from '@/lib/designTokens'
import { useRejectAction } from '../api/queries'
import type { ReplenishmentLine } from '../types'

export interface RejectDialogProps {
  selected: ReplenishmentLine[]
  isOpen: boolean
  onClose: () => void
}

export function RejectDialog({ selected, isOpen, onClose }: RejectDialogProps) {
  const { t } = useTranslation('replenishment')
  const [reason, setReason] = useState('')
  const mutation = useRejectAction()
  const canSubmit = selected.length > 0 && reason.trim() !== ''

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!canSubmit) return
    try {
      await mutation.mutateAsync({ request_ids: selected.map((line) => line.id), reason: reason.trim() })
      toast.success(t('dialog.rejected'))
      onClose()
    } catch (error) {
      toast.error(getErrorMessage(error))
    }
  }

  return (
    <RequirePermission permission="replenishment.process">
      <Modal isOpen={isOpen} onClose={onClose} title={t('actions.reject')}>
        <form className="space-y-4" onSubmit={(event) => { void submit(event) }}>
          <div>
            <label htmlFor="replenishment-reject-reason" className={tokens.label.base}>{t('dialog.reason')}</label>
            <textarea
              id="replenishment-reject-reason"
              value={reason}
              onChange={(event) => { setReason(event.target.value) }}
              className={tokens.textarea.base}
              maxLength={500}
              rows={4}
            />
          </div>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={onClose}>{t('dialog.cancel')}</Button>
            <Button type="submit" variant="danger" disabled={!canSubmit || mutation.isPending}>{t('dialog.submit')}</Button>
          </div>
        </form>
      </Modal>
    </RequirePermission>
  )
}
