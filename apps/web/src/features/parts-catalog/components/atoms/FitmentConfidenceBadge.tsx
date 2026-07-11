import { useTranslation } from 'react-i18next'
import { ShieldCheck, ShieldAlert, ShieldQuestion } from 'lucide-react'
import { cn } from '@/lib/utils'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface FitmentConfidenceBadgeProps {
  confidence: number
  className?: string
}

export function FitmentConfidenceBadge({ confidence, className }: FitmentConfidenceBadgeProps) {
  const { t } = useTranslation(['parts-catalog'])

  if (confidence >= 80) {
    return (
      <span
        className={cn(
          'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
          `${colorTokens.intent.available.bgSubtle} ${colorTokens.intent.available.textStrong} ring-1 ring-inset ${colorTokens.intent.available.ring}`,
          className
        )}
      >
        <ShieldCheck className="h-3 w-3" />
        {t('parts-catalog:fitment.highConfidence')}
      </span>
    )
  }

  if (confidence >= 50) {
    return (
      <span
        className={cn(
          'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
          `${colorTokens.intent.caution.bgSubtle} ${colorTokens.intent.caution.textStrong} ring-1 ring-inset ${colorTokens.intent.caution.ring}`,
          className
        )}
      >
        <ShieldAlert className="h-3 w-3" />
        {t('parts-catalog:fitment.mediumConfidence', { confidence })}
      </span>
    )
  }

  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
        `${colorTokens.intent.notice.bgSubtle} ${colorTokens.intent.notice.textStrong} ring-1 ring-inset ${colorTokens.intent.notice.ring}`,
        className
      )}
    >
      <ShieldQuestion className="h-3 w-3" />
      {t('parts-catalog:fitment.lowConfidence', { confidence })}
    </span>
  )
}
