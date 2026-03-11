import { useTranslation } from 'react-i18next'
import { ShieldCheck, ShieldAlert, ShieldQuestion } from 'lucide-react'
import { cn } from '@/lib/utils'

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
          'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20',
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
          'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-600/20',
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
        'bg-orange-50 text-orange-700 ring-1 ring-inset ring-orange-600/20',
        className
      )}
    >
      <ShieldQuestion className="h-3 w-3" />
      {t('parts-catalog:fitment.lowConfidence', { confidence })}
    </span>
  )
}
