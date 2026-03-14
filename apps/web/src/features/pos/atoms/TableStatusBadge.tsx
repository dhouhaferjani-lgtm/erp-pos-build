import { useTranslation } from 'react-i18next'

const STATUS_STYLES: Record<string, string> = {
  available: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
  occupied: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
  reserved: 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
  cleaning: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
}

interface TableStatusBadgeProps {
  status: string
}

export function TableStatusBadge({ status }: TableStatusBadgeProps) {
  const { t } = useTranslation('pos')

  return (
    <span
      className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${STATUS_STYLES[status] ?? 'bg-gray-100 text-gray-800'}`}
    >
      {t(`tables.status.${status}`)}
    </span>
  )
}
