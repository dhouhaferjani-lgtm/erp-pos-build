import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Modal } from '@/components/pos/Modal'
import { Button } from '@/components/ui/Button'
import { enrollLoyalty } from '@/lib/loyalty/loyaltyApi'
import type { AttachedCheckoutCustomer } from '@/stores/paymentStore'

interface Props {
  open: boolean
  customer: AttachedCheckoutCustomer
  onClose: () => void
  /** Called after `enrollLoyalty` resolves successfully. */
  onEnrolled: () => void
}

/**
 * Cashier-facing enroll dialog (LB-3b). Posts to the SAME find-or-create
 * balance endpoint as `useLoyaltyBalance`, but with a phone the cashier
 * typed — this is how a phone-less attached customer gets enrolled.
 */
export function LoyaltyEnrollDialog({ open, customer, onClose, onEnrolled }: Props) {
  const { t } = useTranslation('pos')
  const [phone, setPhone] = useState(customer.phone ?? '')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const handleSubmit = async () => {
    setSubmitting(true)
    setError(null)
    try {
      await enrollLoyalty(customer.id, phone)
      onEnrolled()
    } catch {
      setError(t('loyalty.enrollFailed'))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal
      isOpen={open}
      onClose={onClose}
      title={t('loyalty.enrollTitle')}
      size="sm"
      closable={!submitting}
    >
      <div className="grid gap-3">
        <label className="grid gap-1 text-sm text-ink" htmlFor="loyalty-enroll-phone">
          {t('loyalty.enrollPhoneLabel')}
          <input
            id="loyalty-enroll-phone"
            aria-label={t('loyalty.enrollPhoneLabel')}
            value={phone}
            onChange={(event) => setPhone(event.target.value)}
            className="rounded-ctl border border-border-strong bg-surface-raised px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-action focus:outline-none focus:ring-1 focus:ring-action"
          />
        </label>
        {error && <div className="text-xs font-medium text-danger-strong">{error}</div>}
        <Button
          variant="primary"
          onClick={() => void handleSubmit()}
          loading={submitting}
          disabled={phone.trim() === ''}
        >
          {t('loyalty.enrollSubmit')}
        </Button>
      </div>
    </Modal>
  )
}
