import { AlertCircle } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { tokens } from '@/lib/designTokens'

interface RejectedBadgeProps {
  reason: string | null
}

export function RejectedBadge({ reason }: RejectedBadgeProps) {
  const { t } = useTranslation('customer-history-audit')

  return (
    <span
      className={`${tokens.badge.base} ${tokens.badge.red} gap-1`}
      aria-label={t('rejected')}
      title={reason ?? undefined}
    >
      <AlertCircle className="w-3 h-3 flex-shrink-0" aria-hidden="true" />
      {t('rejected')}
    </span>
  )
}
