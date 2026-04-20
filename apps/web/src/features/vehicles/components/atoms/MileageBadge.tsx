import { Gauge } from 'lucide-react'
import { textColors } from '@/lib/designTokens'

interface MileageBadgeProps {
  mileage: number | null
  unit?: 'km' | 'mi'
  className?: string
}

export function MileageBadge({ mileage, unit = 'km', className }: MileageBadgeProps) {
  if (mileage === null) {
    return null
  }

  const formatted = new Intl.NumberFormat(undefined).format(mileage)

  return (
    <span
      className={`inline-flex items-center gap-1 ${textColors.secondary} ${className ?? ''}`.trim()}
    >
      <Gauge className="h-4 w-4" aria-hidden="true" />
      <span>
        {formatted} {unit}
      </span>
    </span>
  )
}
