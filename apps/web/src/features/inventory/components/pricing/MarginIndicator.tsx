import { AlertCircle, CheckCircle, AlertTriangle, XCircle } from 'lucide-react'
import { colors, textColors, borderColors } from '@/lib/designTokens'
import { formatPercent } from '@/lib/format'

type MarginLevel = 'green' | 'yellow' | 'orange' | 'red'

interface MarginIndicatorProps {
  level: MarginLevel
  message: string
  marginPercent?: number
  showPercentage?: boolean
  size?: 'sm' | 'md' | 'lg'
}

// Semantic color mapping per margin level. The `orange` ("very low") level
// shares the warning palette with `yellow` ("low") — both surface as the
// warning token rather than an ad-hoc orange/amber literal.
const LEVEL_CONFIGS = {
  green: {
    icon: CheckCircle,
    bgColor: colors.success[100],
    textColor: textColors.success,
    borderColor: borderColors.success,
    iconColor: textColors.success,
  },
  yellow: {
    icon: AlertTriangle,
    bgColor: colors.warning[100],
    textColor: textColors.warning,
    borderColor: borderColors.warning,
    iconColor: textColors.warningDark,
  },
  orange: {
    icon: AlertCircle,
    bgColor: colors.warning[100],
    textColor: textColors.warning,
    borderColor: borderColors.warning,
    iconColor: textColors.warningDark,
  },
  red: {
    icon: XCircle,
    bgColor: colors.error[100],
    textColor: textColors.error,
    borderColor: borderColors.error,
    iconColor: textColors.error,
  },
}

const SIZE_CONFIGS = {
  sm: {
    padding: 'px-2 py-1',
    iconSize: 'h-3 w-3',
    textSize: 'text-xs',
    gap: 'gap-1',
  },
  md: {
    padding: 'px-3 py-2',
    iconSize: 'h-4 w-4',
    textSize: 'text-sm',
    gap: 'gap-2',
  },
  lg: {
    padding: 'px-4 py-3',
    iconSize: 'h-5 w-5',
    textSize: 'text-base',
    gap: 'gap-2',
  },
}

export function MarginIndicator({
  level,
  message,
  marginPercent,
  showPercentage = true,
  size = 'md',
}: MarginIndicatorProps) {
  const config = LEVEL_CONFIGS[level]
  const sizeConfig = SIZE_CONFIGS[size]
  const Icon = config.icon

  return (
    <div
      className={`
        inline-flex items-center rounded-md border
        ${config.bgColor}
        ${config.borderColor}
        ${sizeConfig.padding}
        ${sizeConfig.gap}
      `}
    >
      <Icon className={`${sizeConfig.iconSize} ${config.iconColor}`} />
      <span className={`font-medium ${config.textColor} ${sizeConfig.textSize}`}>
        {message}
      </span>
      {showPercentage && marginPercent !== undefined && (
        <span className={`font-semibold ${config.textColor} ${sizeConfig.textSize}`}>
          ({formatPercent(marginPercent)})
        </span>
      )}
    </div>
  )
}

interface MarginBadgeProps {
  level: MarginLevel
  compact?: boolean
}

export function MarginBadge({ level, compact = false }: MarginBadgeProps) {
  const config = LEVEL_CONFIGS[level]

  const labels = {
    green: 'Good',
    yellow: 'Low',
    orange: 'Very Low',
    red: 'Loss',
  }

  if (compact) {
    return (
      <span
        className={`
          inline-flex h-2 w-2 rounded-full
          ${config.bgColor}
          ${config.borderColor}
          border-2
        `}
        title={labels[level]}
      />
    )
  }

  return (
    <span
      className={`
        inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
        ${config.bgColor}
        ${config.textColor}
      `}
    >
      {labels[level]}
    </span>
  )
}
