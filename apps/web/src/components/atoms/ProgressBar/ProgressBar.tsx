import { colors, borderRadius } from '@/lib/designTokens'

export interface ProgressBarProps {
  percent: number
  className?: string
  size?: 'sm' | 'md' | 'lg'
  variant?: 'primary' | 'success' | 'warning' | 'error'
}

const sizeStyles: Record<string, string> = {
  sm: 'h-1.5',
  md: 'h-2.5',
  lg: 'h-4',
}

const variantStyles: Record<string, string> = {
  primary: colors.primary[600],
  success: colors.success[600],
  warning: colors.warning[600],
  error: colors.error[600],
}

export function ProgressBar({
  percent,
  className = '',
  size = 'md',
  variant = 'primary',
}: ProgressBarProps) {
  const clampedPercent = Math.max(0, Math.min(100, percent))

  return (
    <div
      className={`w-full ${colors.neutral[200]} ${borderRadius.full} ${sizeStyles[size]} ${className}`}
    >
      <div
        className={`${sizeStyles[size]} ${borderRadius.full} ${variantStyles[variant]} transition-all duration-300`}
        style={{ width: `${clampedPercent}%` }}
      />
    </div>
  )
}
