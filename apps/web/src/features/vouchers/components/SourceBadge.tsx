import { useTranslation } from 'react-i18next'
import { tokens } from '@/lib/designTokens'
import type { VoucherSource } from '../types/voucher'

interface SourceBadgeProps {
  source: VoucherSource
}

// indigo and pink have no design-token equivalent yet — kept as raw Tailwind.
const SOURCE_CLASSES: Record<VoucherSource, string> = {
  refund: tokens.badge.blue,
  exchange_surplus: 'bg-indigo-100 text-indigo-800',
  goodwill: tokens.badge.purple,
  loyalty_credit: tokens.badge.yellow,
  gift_card_purchase: 'bg-pink-100 text-pink-800',
  promotional: tokens.badge.green,
}

export function SourceBadge({ source }: SourceBadgeProps) {
  const { t } = useTranslation('vouchers')
  return (
    <span
      className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${SOURCE_CLASSES[source] ?? tokens.badge.gray}`}
    >
      {t(`sources.${source}`)}
    </span>
  )
}
