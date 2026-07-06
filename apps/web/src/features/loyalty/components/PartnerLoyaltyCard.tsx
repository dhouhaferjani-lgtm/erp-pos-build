import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Award } from 'lucide-react'
import { Button, Input, FormField } from '@/components/atoms'
import { Badge } from '@/components/atoms/Badge/Badge'
import { Modal } from '@/components/organisms/Modal/Modal'
import { usePermissions } from '@/hooks/usePermissions'
import { borderColors, textColors } from '@/lib/designTokens'
import { usePartnerLoyalty, useEnrollPartner } from '../hooks/usePartnerLoyalty'

interface PartnerLoyaltyCardProps {
  partnerId: string
  partnerPhone: string | null
}

interface EnrollPartnerModalProps {
  isOpen: boolean
  onClose: () => void
  partnerPhone: string | null
  onSubmit: (phone: string) => void
  isPending: boolean
}

function EnrollPartnerModal({ isOpen, onClose, partnerPhone, onSubmit, isPending }: EnrollPartnerModalProps) {
  const { t } = useTranslation(['loyalty', 'common'])
  const [phone, setPhone] = useState(partnerPhone ?? '')

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault()
    if (phone.trim().length > 0) {
      onSubmit(phone)
    }
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="sm">
      <Modal.Header title={t('loyalty:partnerCard.enroll')} onClose={onClose} />
      <form onSubmit={handleSubmit}>
        <Modal.Content>
          <div className="space-y-3">
            <FormField label={t('loyalty:partnerCard.phoneLabel')} htmlFor="loyalty-enroll-phone">
              <Input
                id="loyalty-enroll-phone"
                data-testid="loyalty-enroll-phone-input"
                value={phone}
                onChange={(e) => { setPhone(e.target.value); }}
              />
            </FormField>
            <p className={`text-sm ${textColors.tertiary}`}>{t('loyalty:partnerCard.enrollNote')}</p>
          </div>
        </Modal.Content>
        <Modal.Footer>
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('common:cancel')}
          </Button>
          <Button
            type="submit"
            data-testid="loyalty-enroll-submit"
            disabled={isPending || phone.trim().length === 0}
          >
            {isPending ? t('common:saving') : t('loyalty:partnerCard.enroll')}
          </Button>
        </Modal.Footer>
      </form>
    </Modal>
  )
}

export function PartnerLoyaltyCard({ partnerId, partnerPhone }: PartnerLoyaltyCardProps) {
  const { t } = useTranslation(['loyalty', 'common'])
  const { hasPermission } = usePermissions()
  const [showEnrollModal, setShowEnrollModal] = useState(false)
  const { data, isLoading } = usePartnerLoyalty(partnerId, partnerId.length > 0)
  const { mutate, isPending } = useEnrollPartner(partnerId)

  const canEnroll = hasPermission('loyalty.enroll')

  const handleEnrollSubmit = (phone: string) => {
    mutate(
      { phone },
      {
        onSuccess: () => {
          setShowEnrollModal(false)
        },
      },
    )
  }

  return (
    <div className={`rounded-lg border ${borderColors.light} bg-white p-6`}>
      <h2 className={`mb-4 flex items-center gap-2 text-lg font-semibold ${textColors.primary}`}>
        <Award className={`h-5 w-5 ${textColors.disabled}`} />
        {t('loyalty:partnerCard.title')}
      </h2>

      {isLoading && <div className={`text-sm ${textColors.tertiary}`}>{t('common:status.loading')}</div>}

      {!isLoading && data && !data.is_member && (
        <div className="space-y-3">
          <p className={`text-sm ${textColors.tertiary}`}>{t('loyalty:partnerCard.notMember')}</p>
          {canEnroll && (
            <Button type="button" size="sm" onClick={() => { setShowEnrollModal(true); }}>
              {t('loyalty:partnerCard.enroll')}
            </Button>
          )}
        </div>
      )}

      {!isLoading && data && data.is_member && (
        <dl className="space-y-3">
          {data.enrollments.map((enrollment) => (
            <div
              key={enrollment.enrollment_id}
              className={`flex items-center justify-between border-t ${borderColors.light} pt-3 first:border-t-0 first:pt-0`}
            >
              <div>
                <dt className={`text-sm font-medium ${textColors.primary}`}>{enrollment.program_name}</dt>
                <dd className={`text-xs ${textColors.tertiary}`}>
                  {t('loyalty:partnerCard.tier')}: {enrollment.tier ?? '-'}
                </dd>
              </div>
              <div className="flex items-center gap-2">
                <dd className={`text-sm font-medium ${textColors.primary}`}>
                  {enrollment.balance} {t('loyalty:partnerCard.points')}
                </dd>
                {enrollment.tier && <Badge variant="info">{enrollment.tier}</Badge>}
              </div>
            </div>
          ))}
        </dl>
      )}

      <EnrollPartnerModal
        isOpen={showEnrollModal}
        onClose={() => { setShowEnrollModal(false); }}
        partnerPhone={partnerPhone}
        onSubmit={handleEnrollSubmit}
        isPending={isPending}
      />
    </div>
  )
}
