import { useTranslation } from 'react-i18next'
import type { VoucherSource } from '../types/voucher'

interface SourceBadgeProps {
  source: VoucherSource
}

const SOURCE_CLASSES: Record<VoucherSource, string> = {
  Refund: 'bg-blue-100 text-blue-800',
  ExchangeSurplus: 'bg-indigo-100 text-indigo-800',
  Goodwill: 'bg-purple-100 text-purple-800',
  LoyaltyCredit: 'bg-yellow-100 text-yellow-800',
  GiftCard: 'bg-pink-100 text-pink-800',
  Promotional: 'bg-green-100 text-green-800',
}

export function SourceBadge({ source }: SourceBadgeProps) {
  const { t } = useTranslation('vouchers')
  return (
    <span
      className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${SOURCE_CLASSES[source] ?? 'bg-gray-100 text-gray-700'}`}
    >
      {t(`sources.${source}`)}
    </span>
  )
}
