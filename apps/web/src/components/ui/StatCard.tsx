import type { LucideIcon } from 'lucide-react'
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
    <div className={cn('bg-white rounded-lg border p-6', className)}>
      <div className="flex items-center justify-between">
        <div className="flex-1">
          <p className="text-sm font-medium text-gray-600">{label}</p>
          <p className="mt-2 text-3xl font-semibold text-gray-900">{value}</p>
          {trend && (
            <p className="mt-2 text-sm text-gray-600">
              <span
                className={cn(
                  'font-medium',
                  trend.isPositive ? 'text-green-600' : 'text-red-600'
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
          <div className="flex items-center justify-center w-12 h-12 bg-gray-100 rounded-lg">
            <Icon className="w-6 h-6 text-gray-600" />
          </div>
        )}
      </div>
    </div>
  )
}
