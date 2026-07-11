import { useTranslation } from 'react-i18next'
import type { CountingStatus } from '../types'
import { cn } from '@/lib/utils'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface Props {
  status: CountingStatus
  className?: string
}

const countingToneClasses: Record<CountingStatus, string> = {
  draft: `${colorTokens.surface.muted} ${colorTokens.text.secondary}`,
  scheduled: `${colorTokens.intent.primary.bgSoft} ${colorTokens.intent.primary.textStrong}`,
  count_1_in_progress: `${colorTokens.intent.warning.bgSoft} ${colorTokens.intent.warning.textStronger}`,
  count_1_completed: `${colorTokens.intent.warning.bgSoftStrong} ${colorTokens.intent.warning.textStrongest}`,
  count_2_in_progress: `${colorTokens.intent.notice.bgSoft} ${colorTokens.intent.notice.textStronger}`,
  count_2_completed: `${colorTokens.intent.notice.bgSoftStrong} ${colorTokens.intent.notice.textStrongest}`,
  count_3_in_progress: `${colorTokens.intent.accent.bgSoft} ${colorTokens.intent.accent.textStronger}`,
  count_3_completed: `${colorTokens.intent.accent.bgSoftStrong} ${colorTokens.intent.accent.textStrongest}`,
  pending_review: `${colorTokens.intent.caution.bgSoft} ${colorTokens.intent.caution.textStronger}`,
  finalized: `${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.textStronger}`,
  cancelled: `${colorTokens.intent.danger.bgSoft} ${colorTokens.intent.danger.textStrong}`,
}

export function CountingStatusBadge({ status, className }: Props) {
  const { t } = useTranslation('inventory')

  return (
    <span
      className={cn(
        'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
        countingToneClasses[status],
        className
      )}
    >
      {t(`counting.status.${status}`)}
    </span>
  )
}
