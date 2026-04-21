import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Modal, ModalContent, ModalFooter, ModalHeader } from '@/components/organisms/Modal'
import { Button } from '@/components/atoms/Button/Button'
import { Input } from '@/components/atoms/Input/Input'
import { textColors } from '@/lib/designTokens'

interface CompleteWorkOrderDialogProps {
  isOpen: boolean
  onClose: () => void
  onConfirm: (payload: { completion_mileage: number | null }) => void
  isPending: boolean
}

/**
 * Modal dialog prompting the user for the vehicle mileage at the time of
 * WorkOrder completion. Mileage is optional — an empty field submits `null`
 * which the backend understands as "skip mileage logging".
 *
 * The POST payload travels up via `onConfirm`; the parent component owns the
 * mutation and any optimistic-concurrency metadata (`expected_updated_at`).
 */
export function CompleteWorkOrderDialog({
  isOpen,
  onClose,
  onConfirm,
  isPending,
}: CompleteWorkOrderDialogProps) {
  const { t } = useTranslation('workshop-work-orders')
  const [mileage, setMileage] = useState<string>('')

  const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const trimmed = mileage.trim()
    const parsed = trimmed === '' ? null : Number(trimmed)
    const completion_mileage =
      parsed === null || Number.isNaN(parsed) || parsed < 0 ? null : Math.trunc(parsed)
    onConfirm({ completion_mileage })
  }

  const handleClose = () => {
    setMileage('')
    onClose()
  }

  return (
    <Modal isOpen={isOpen} onClose={handleClose} size="sm">
      <ModalHeader title={t('complete_dialog.title')} onClose={handleClose} />
      <form onSubmit={handleSubmit} aria-label={t('complete_dialog.title')}>
        <ModalContent>
          <div>
            <label htmlFor="complete-mileage" className={`block text-sm font-medium ${textColors.primary}`}>
              {t('complete_dialog.mileage_label')}
            </label>
            <Input
              id="complete-mileage"
              name="completion_mileage"
              type="number"
              inputMode="numeric"
              min={0}
              step={1}
              placeholder="0"
              value={mileage}
              onChange={(event) => {
                setMileage(event.target.value)
              }}
              className="mt-1 w-full"
              autoFocus
            />
            <p className={`mt-1 text-xs ${textColors.tertiary}`}>
              {t('complete_dialog.mileage_hint')}
            </p>
          </div>
        </ModalContent>
        <ModalFooter>
          <Button type="button" variant="secondary" onClick={handleClose} disabled={isPending}>
            {t('complete_dialog.cancel')}
          </Button>
          <Button type="submit" variant="primary" disabled={isPending}>
            {t('complete_dialog.confirm')}
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
