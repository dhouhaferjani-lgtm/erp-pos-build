import { useTranslation } from 'react-i18next'
import { formatPercent } from '@/lib/format'

export type MarginLevel = 'green' | 'yellow' | 'orange' | 'red'

export interface MarginIndicatorProps {
  level: MarginLevel
  actualMargin: number | null
  message: string
  targetMargin?: number
  minimumMargin?: number
  lossAmount?: number
  showDetails?: boolean
  compact?: boolean
}

const levelStyles: Record<MarginLevel, { dot: string; text: string; bg: string }> = {
  green: {
    dot: 'bg-green-500',
    text: 'text-green-700 dark:text-green-400',
    bg: 'bg-green-50 dark:bg-green-900/20',
  },
  yellow: {
    dot: 'bg-yellow-500',
    text: 'text-yellow-700 dark:text-yellow-400',
    bg: 'bg-yellow-50 dark:bg-yellow-900/20',
  },
  orange: {
    dot: 'bg-orange-500',
    text: 'text-orange-700 dark:text-orange-400',
    bg: 'bg-orange-50 dark:bg-orange-900/20',
  },
  red: {
    dot: 'bg-red-500',
    text: 'text-red-700 dark:text-red-400',
    bg: 'bg-red-50 dark:bg-red-900/20',
  },
}

const levelIcons: Record<MarginLevel, string> = {
  green: '✓',
  yellow: '!',
  orange: '!!',
  red: '✕',
}

export function MarginIndicator({
  level,
  actualMargin,
  message,
  targetMargin,
  minimumMargin,
  lossAmount,
  showDetails = false,
  compact = false,
}: MarginIndicatorProps) {
  const { t } = useTranslation(['inventory'])
  const styles = levelStyles[level]
  const icon = levelIcons[level]

  const formatMargin = (value: number | null): string => {
    if (value === null) return '-'
    return formatPercent(value)
  }

  const formatCurrency = (value: number): string => {
    return new Intl.NumberFormat('fr-TN', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(value)
  }

  if (compact) {
    return (
      <div className={`inline-flex items-center gap-1.5 ${styles.text}`}>
        <span className={`h-2 w-2 rounded-full ${styles.dot}`} />
        <span className="text-sm font-medium">{formatMargin(actualMargin)}</span>
      </div>
    )
  }

  return (
    <div className={`rounded-md px-3 py-2 ${styles.bg}`}>
      <div className="flex items-center gap-2">
        <span className={`flex h-5 w-5 items-center justify-center rounded-full ${styles.dot} text-xs font-bold text-white`}>
          {icon}
        </span>
        <div className="flex-1">
          <div className={`flex items-center gap-2 ${styles.text}`}>
            <span className="font-semibold">{formatMargin(actualMargin)}</span>
            <span className="text-sm">{message}</span>
          </div>

          {showDetails && (
            <div className="mt-1 text-xs text-gray-600 dark:text-gray-400">
              {level === 'red' && lossAmount !== undefined && (
                <span className="font-medium text-red-600 dark:text-red-400">
                  {t('inventory:margin.lossAmount', { amount: formatCurrency(lossAmount) })}
                </span>
              )}
              {level === 'yellow' && targetMargin !== undefined && (
                <span>
                  {t('inventory:margin.targetIs', { margin: formatMargin(targetMargin) })}
                </span>
              )}
              {level === 'orange' && minimumMargin !== undefined && (
                <span>
                  {t('inventory:margin.minimumIs', { margin: formatMargin(minimumMargin) })}
                </span>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

export default MarginIndicator
