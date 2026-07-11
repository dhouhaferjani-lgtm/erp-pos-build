import { useTranslation } from 'react-i18next'
import { StatusBadge, statusTone, type StatusTone } from '@/components/atoms/StatusBadge'

const tableToneOverrides: Record<string, StatusTone> = {
  available: 'success',
  occupied: 'danger',
  reserved: 'info',
  cleaning: 'warning',
}

interface TableStatusBadgeProps {
  status: string
}

export function TableStatusBadge({ status }: TableStatusBadgeProps) {
  const { t } = useTranslation('pos')

  return (
    <StatusBadge tone={statusTone(status, tableToneOverrides)}>
      {t(`tables.status.${status}`)}
    </StatusBadge>
  )
}
