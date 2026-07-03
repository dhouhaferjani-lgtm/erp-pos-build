import type { LucideIcon } from 'lucide-react'
import { borderColors, colors, textColors } from '../../lib/designTokens'
import { cn } from '../../lib/utils'

export interface StatCardProps {
  label: string
  value: string | number
  icon?: LucideIcon
  trend?: {
    value: number
    label: string
    isPositive?: boolean
  }
  className?: string
}

export function StatCard({ label, value, icon: Icon, trend, className }: StatCardProps) {
  return (
    <div className={cn('rounded-lg border p-6', colors.white, borderColors.light, className)}>
      <div className="flex items-center justify-between">
        <div className="flex-1">
          <p className={cn('text-sm font-medium', textColors.tertiary)}>{label}</p>
          <p className={cn('mt-2 text-3xl font-semibold', textColors.primary)}>{value}</p>
          {trend && (
            <p className={cn('mt-2 text-sm', textColors.tertiary)}>
              <span
                className={cn(
                  'font-medium',
                  trend.isPositive ? textColors.success : textColors.error
                )}
              >
                {trend.isPositive ? '+' : ''}
                {trend.value}%
              </span>{' '}
              {trend.label}
            </p>
          )}
        </div>
        {Icon && (
          <div className={cn('flex h-12 w-12 items-center justify-center rounded-lg', colors.neutral[100])}>
            <Icon className={cn('h-6 w-6', textColors.tertiary)} />
          </div>
        )}
      </div>
    </div>
  )
}
