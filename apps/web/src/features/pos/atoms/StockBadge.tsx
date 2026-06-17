import { cn } from '@/lib/utils'
import { tokens, colors } from '@/lib/designTokens'

export interface StockBadgeProps {
  quantity: number
  threshold?: number
  showQuantity?: boolean
  unit?: string
  size?: 'sm' | 'md' | 'lg'
  className?: string
}

type StockStatus = 'in-stock' | 'low-stock' | 'out-of-stock'

export function StockBadge({
  quantity,
  threshold = 10,
  showQuantity = false,
  unit = 'units',
  size = 'sm',
  className,
}: StockBadgeProps) {
  // Determine stock status
  const getStockStatus = (): StockStatus => {
    if (quantity <= 0) return 'out-of-stock'
    if (quantity <= threshold) return 'low-stock'
    return 'in-stock'
  }

  const status = getStockStatus()

  // Status configuration — semantic tokens (badge bg+text, dot fill)
  const statusConfig = {
    'in-stock': {
      label: 'In Stock',
      badgeColor: tokens.badge.green,
      dotColor: colors.success[600],
    },
    'low-stock': {
      label: 'Low Stock',
      badgeColor: tokens.badge.yellow,
      dotColor: colors.warning[600],
    },
    'out-of-stock': {
      label: 'Out of Stock',
      badgeColor: tokens.badge.red,
      dotColor: colors.error[600],
    },
  }

  const config = statusConfig[status]

  // Size styles
  const sizeClasses = {
    sm: 'text-xs px-2 py-1',
    md: 'text-sm px-3 py-1.5',
    lg: 'text-base px-4 py-2',
  }

  return (
    <span
      className={cn(
        // Base styles
        'inline-flex items-center gap-1.5 rounded-full font-medium',

        // Status colors
        config.badgeColor,

        // Size
        sizeClasses[size],

        // Custom classes
        className
      )}
    >
      {/* Status indicator dot */}
      <span className={cn('w-1.5 h-1.5 rounded-full', config.dotColor)} />

      {/* Status label */}
      <span>{config.label}</span>

      {/* Quantity display (optional) */}
      {showQuantity && (
        <span className="font-semibold">
          ({quantity} {unit})
        </span>
      )}
    </span>
  )
}
