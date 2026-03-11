import { useTranslation } from 'react-i18next'
import { Badge } from '@/components/atoms/Badge/Badge'
import type { ProgramStatus } from '../types/loyalty'

const STATUS_VARIANTS: Record<ProgramStatus, 'default' | 'success' | 'warning' | 'danger'> = {
  draft: 'default',
  active: 'success',
  paused: 'warning',
  archived: 'danger',
}

interface ProgramStatusBadgeProps {
  status: ProgramStatus
}

export function ProgramStatusBadge({ status }: ProgramStatusBadgeProps) {
  const { t } = useTranslation('loyalty')
  return (
    <Badge variant={STATUS_VARIANTS[status]}>
      {t(`statuses.${status}`)}
    </Badge>
  )
}
