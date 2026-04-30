import { useTranslation } from 'react-i18next'
import type { VoucherStatus } from '../types/voucher'

interface StatusBadgeProps {
  status: VoucherStatus
}

const STATUS_CLASSES: Record<VoucherStatus, string> = {
  Issued: 'bg-green-100 text-green-800',
  PartiallyRedeemed: 'bg-blue-100 text-blue-800',
  FullyRedeemed: 'bg-gray-100 text-gray-600',
  Voided: 'bg-red-100 text-red-800',
  Expired: 'bg-orange-100 text-orange-800',
}

export function StatusBadge({ status }: StatusBadgeProps) {
  const { t } = useTranslation('vouchers')
  return (
    <span
      className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_CLASSES[status] ?? 'bg-gray-100 text-gray-700'}`}
    >
      {t(`statuses.${status}`)}
    </span>
  )
}
