import { Tag, Ticket, Star, Percent } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useCurrency } from '@/hooks/useCurrency'
import type { DiscountLineData } from '../../api/discountApi'

const SOURCE_ICONS = {
  promotion: Tag,
  coupon: Ticket,
  loyalty: Star,
  manual: Percent,
} as const

const SOURCE_COLORS = {
  promotion: 'text-purple-600 bg-purple-50 border-purple-200',
  coupon: 'text-emerald-600 bg-emerald-50 border-emerald-200',
  loyalty: 'text-amber-600 bg-amber-50 border-amber-200',
  manual: 'text-blue-600 bg-blue-50 border-blue-200',
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
