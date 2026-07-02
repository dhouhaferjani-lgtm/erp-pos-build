import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { tokens } from '@/lib/designTokens'

interface TerminalStatusBadgeProps {
  isActive: boolean
  className?: string
}

/**
 * Badge component to display terminal status (Active/Inactive)
 */
export function TerminalStatusBadge({ isActive, className }: TerminalStatusBadgeProps) {
  const { t } = useTranslation('pos')

  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
        isActive ? tokens.badge.green : tokens.badge.gray,
        className
      )}
    >
      {isActive ? t('terminal.active') : t('terminal.inactive')}
    </span>
  )
}
