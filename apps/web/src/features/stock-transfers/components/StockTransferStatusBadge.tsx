import { useTranslation } from 'react-i18next'
import { tokens } from '@/lib/designTokens'
import type { StockTransferStatus } from '../types'

const statusVariant: Record<StockTransferStatus, string> = {
  draft: tokens.badge.gray,
  in_transit: tokens.badge.blue,
  partially_received: tokens.badge.yellow,
  completed: tokens.badge.green,
  closed_with_writeoff: tokens.badge.red,
  closed_returned: tokens.badge.gray,
  cancelled: tokens.badge.red,
}

export interface StockTransferStatusBadgeProps {
  status: StockTransferStatus
}

export function StockTransferStatusBadge({ status }: StockTransferStatusBadgeProps) {
  const { t } = useTranslation('stock-transfers')
  return <span className={`${tokens.badge.base} ${statusVariant[status]}`}>{t(`status.${status}`)}</span>
}
