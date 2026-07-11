import { useTranslation } from 'react-i18next'
import { StatusBadge as SharedStatusBadge, statusTone, type StatusTone } from '@/components/atoms/StatusBadge'
import type { VoucherStatus } from '../types/voucher'

interface StatusBadgeProps {
  status: VoucherStatus
}

const voucherToneOverrides: Record<VoucherStatus, StatusTone> = {
  Issued: 'success',
  PartiallyRedeemed: 'info',
  FullyRedeemed: 'neutral',
  Voided: 'danger',
  Expired: 'warning',
}

export function StatusBadge({ status }: StatusBadgeProps) {
  const { t } = useTranslation('vouchers')
  return (
    <SharedStatusBadge tone={statusTone(status, voucherToneOverrides)} className="px-2">
      {t(`statuses.${status}`)}
    </SharedStatusBadge>
  )
}
