import { useTranslation } from 'react-i18next'
import { formatPercent } from '@/lib/format'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

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
    dot: `${colorTokens.intent.success.bg}`,
    text: `${colorTokens.intent.success.textStrong} ${colorTokens.variants.darkTextGreen400}`,
    bg: `${colorTokens.intent.success.bgSubtle} ${colorTokens.variants.darkBgGreen900Alpha20}`,
  },
  yellow: {
    dot: `${colorTokens.intent.warning.bg}`,
    text: `${colorTokens.intent.warning.textStrong} ${colorTokens.variants.darkTextYellow400}`,
    bg: `${colorTokens.intent.warning.bgSubtle} ${colorTokens.variants.darkBgYellow900Alpha20}`,
  },
  orange: {
    dot: colorTokens.variants.bgOrange500,
    text: `${colorTokens.intent.notice.textStrong} ${colorTokens.variants.darkTextOrange400}`,
    bg: `${colorTokens.intent.notice.bgSubtle} ${colorTokens.variants.darkBgOrange900Alpha20}`,
  },
  red: {
    dot: `${colorTokens.intent.danger.bg}`,
    text: `${colorTokens.intent.danger.textStrong} ${colorTokens.variants.darkTextRed400}`,
    bg: `${colorTokens.intent.danger.bgSubtle} ${colorTokens.variants.darkBgRed900Alpha20}`,
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
        <span className={`flex h-5 w-5 items-center justify-center rounded-full ${styles.dot} text-xs font-bold ${colorTokens.text.inverse}`}>
          {icon}
        </span>
        <div className="flex-1">
          <div className={`flex items-center gap-2 ${styles.text}`}>
            <span className="font-semibold">{formatMargin(actualMargin)}</span>
            <span className="text-sm">{message}</span>
          </div>

          {showDetails && (
            <div className={`mt-1 text-xs ${colorTokens.text.muted} ${colorTokens.variants.darkTextGray400}`}>
              {level === 'red' && lossAmount !== undefined && (
                <span className={`font-medium ${colorTokens.intent.danger.text} ${colorTokens.variants.darkTextRed400}`}>
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
