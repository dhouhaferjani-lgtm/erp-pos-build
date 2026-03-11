import { useTranslation } from 'react-i18next'
import { Badge } from '@/components/atoms/Badge/Badge'
import type { MemberStatus } from '../types/loyalty'

const STATUS_VARIANTS: Record<MemberStatus, 'default' | 'success' | 'danger'> = {
  active: 'success',
  inactive: 'default',
  suspended: 'danger',
}

interface MemberStatusBadgeProps {
  status: MemberStatus
}

export function MemberStatusBadge({ status }: MemberStatusBadgeProps) {
  const { t } = useTranslation('loyalty')
  return (
    <Badge variant={STATUS_VARIANTS[status]}>
      {t(`statuses.${status}`)}
    </Badge>
  )
}
