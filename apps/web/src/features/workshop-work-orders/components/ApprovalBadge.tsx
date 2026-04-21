import { ShieldCheck } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import type { ApprovalMethod } from '../types'

interface ApprovalBadgeProps {
  method: ApprovalMethod
  capturedAt: string | null
}

/**
 * Visual hint that customer approval was captured, with the method used.
 */
export function ApprovalBadge({ method, capturedAt }: ApprovalBadgeProps) {
  const { t } = useTranslation('workshop-work-orders')
  return (
    <span className="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
      <ShieldCheck className="h-3 w-3" aria-hidden />
      {t(`approvalMethod.${method}`)}
      {capturedAt !== null && (
        <span className="text-emerald-600">· {new Date(capturedAt).toLocaleDateString()}</span>
      )}
    </span>
  )
}
