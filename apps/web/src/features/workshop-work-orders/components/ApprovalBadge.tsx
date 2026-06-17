import { ShieldCheck } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { StatusBadge } from '@/components/atoms/StatusBadge'
import type { ApprovalMethod } from '../types'

interface ApprovalBadgeProps {
  method: ApprovalMethod
  capturedAt: string | null
}

/**
 * Visual hint that customer approval was captured, with the method used.
 * Rendered through `StatusBadge` (success tone) so it shares the sanctioned
 * status palette instead of a bespoke off-theme one.
 */
export function ApprovalBadge({ method, capturedAt }: ApprovalBadgeProps) {
  const { t } = useTranslation('workshop-work-orders')
  return (
    <StatusBadge tone="success" className="gap-1">
      <ShieldCheck className="h-3 w-3" aria-hidden />
      {t(`approvalMethod.${method}`)}
      {capturedAt !== null && (
        <span className="opacity-75">· {new Date(capturedAt).toLocaleDateString()}</span>
      )}
    </StatusBadge>
  )
}
