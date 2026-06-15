import { useTranslation } from 'react-i18next'
import { Pause } from 'lucide-react'
import { cn } from '@/lib/utils'
import { colors, textColors } from '@/lib/designTokens'
import { useHeldOrders } from '../hooks/useHeldOrders'

export interface HeldOrdersBadgeProps {
  terminalId: string | undefined
  onClick: () => void
}

/**
 * HeldOrdersBadge - Count indicator for held orders in the POS header.
 *
 * Displays the number of currently held orders with a subtle pulse
 * animation when there are active held orders.
 */
export function HeldOrdersBadge({ terminalId, onClick }: HeldOrdersBadgeProps) {
  const { t } = useTranslation(['pos'])
  const { data: heldOrders } = useHeldOrders(terminalId)

  const count = heldOrders?.length ?? 0

  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        'relative flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
        count > 0
          ? 'bg-amber-50 text-amber-700 hover:bg-amber-100'
          : cn(textColors.tertiary, colors.hover.gray100),
      )}
      title={t('pos:heldOrders.title')}
    >
      <Pause className="h-4 w-4" />
      {count > 0 && (
        <>
          <span className="text-xs">
            {t('pos:heldOrders.badge', { count })}
          </span>
          <span
            className={cn(
              'absolute -right-1 -top-1 flex h-5 w-5 items-center justify-center rounded-full bg-amber-500 text-[10px] font-bold text-white',
              'animate-pulse',
            )}
          >
            {count}
          </span>
        </>
      )}
    </button>
  )
}
