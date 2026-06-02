import { useTranslation } from 'react-i18next'
import { Badge, type BadgeVariant } from '@/components/atoms/Badge'
import type { StockTransferStatus } from '../types'

const statusVariant: Record<StockTransferStatus, BadgeVariant> = {
  draft: 'default',
  in_transit: 'info',
  completed: 'success',
  cancelled: 'danger',
}

export interface StockTransferStatusBadgeProps {
  status: StockTransferStatus
}

export function StockTransferStatusBadge({ status }: StockTransferStatusBadgeProps) {
  const { t } = useTranslation('stock-transfers')
  return <Badge variant={statusVariant[status]}>{t(`status.${status}`)}</Badge>
}
