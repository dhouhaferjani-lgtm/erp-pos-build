import { useTranslation } from 'react-i18next'
import { tokens } from '@/lib/designTokens'

const STATUS_STYLES: Record<string, string> = {
  available: tokens.badge.green,
  occupied: tokens.badge.red,
  reserved: tokens.badge.purple,
  cleaning: tokens.badge.yellow,
}

interface TableStatusBadgeProps {
  status: string
}

export function TableStatusBadge({ status }: TableStatusBadgeProps) {
  const { t } = useTranslation('pos')

  return (
    <span
      className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${STATUS_STYLES[status] ?? tokens.badge.gray}`}
    >
      {t(`tables.status.${status}`)}
    </span>
  )
}
