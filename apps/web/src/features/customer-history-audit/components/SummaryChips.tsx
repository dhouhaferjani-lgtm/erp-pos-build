import { useTranslation } from 'react-i18next'
import { tokens, borderColors } from '@/lib/designTokens'

interface SummaryChipsProps {
  total: number
  rejectedCount: number
}

export function SummaryChips({ total, rejectedCount }: SummaryChipsProps) {
  const { t } = useTranslation('customer-history-audit')

  return (
    <div className="flex flex-wrap gap-2 items-center">
      <span
        className={`${tokens.badge.base} ${tokens.badge.gray} border ${borderColors.light}`}
        data-testid="chip-total"
      >
        {t('summary.searches', { count: total })}
      </span>
      <span
        className={`${tokens.badge.base} ${rejectedCount > 0 ? tokens.badge.red : tokens.badge.green}`}
        data-testid="chip-rejected"
      >
        {t('summary.rejected', { count: rejectedCount })}
      </span>
    </div>
  )
}
