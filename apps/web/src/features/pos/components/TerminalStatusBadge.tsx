import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'

interface TerminalStatusBadgeProps {
  isActive: boolean
  className?: string
}

/**
 * Badge component to display terminal status (Active/Inactive)
 */
export function TerminalStatusBadge({ isActive, className }: TerminalStatusBadgeProps) {
  const { t } = useTranslation()

  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
        isActive
          ? 'bg-green-100 text-green-800'
          : 'bg-gray-100 text-gray-800',
        className
      )}
    >
      {isActive ? t('pos.terminal.active') : t('pos.terminal.inactive')}
    </span>
  )
}
