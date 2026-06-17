import { Tag, Ticket, Star, Percent } from 'lucide-react'
import { cn } from '@/lib/utils'
import { tokens, borderColors } from '@/lib/designTokens'
import { useCurrency } from '@/hooks/useCurrency'
import type { DiscountLineData } from '../../api/discountApi'

const SOURCE_ICONS = {
  promotion: Tag,
  coupon: Ticket,
  loyalty: Star,
  manual: Percent,
} as const

// Source-identity tint (bg + text) via semantic badge tokens, neutral border.
const SOURCE_COLORS = {
  promotion: cn(tokens.badge.purple, borderColors.light),
  coupon: cn(tokens.badge.green, borderColors.light),
  loyalty: cn(tokens.badge.yellow, borderColors.light),
  manual: cn(tokens.badge.blue, borderColors.light),
} as const

export interface AppliedDiscountsBadgeProps {
  line: DiscountLineData
  className?: string
}

export function AppliedDiscountsBadge({ line, className }: AppliedDiscountsBadgeProps) {
  const { currency, toFixed: toFixedCurrency } = useCurrency()
  const Icon = SOURCE_ICONS[line.source]
  const colorClass = SOURCE_COLORS[line.source]

  return (
    <div
      className={cn(
        'flex items-center justify-between px-3 py-2 rounded-lg border text-sm',
        colorClass,
        className,
      )}
      data-testid={`discount-badge-${line.source}`}
    >
      <div className="flex items-center gap-2 min-w-0">
        <Icon className="w-4 h-4 flex-shrink-0" />
        <span className="truncate font-medium">{line.label}</span>
      </div>
      <span className="font-semibold flex-shrink-0 ml-2">
        -{toFixedCurrency(parseFloat(line.discount_amount))} {currency}
      </span>
    </div>
  )
}
