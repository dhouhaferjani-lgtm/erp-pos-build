import { textColors, borderColors } from '@/lib/designTokens'
import { ProgressBar } from '@/components/atoms'
import type { Milestone, MilestoneStatus } from '../api/types'

interface MilestoneItemProps {
  milestone: Milestone
}

function StatusIcon({ status }: { status: MilestoneStatus }) {
  switch (status) {
    case 'completed':
      return (
        <svg className={`h-5 w-5 ${textColors.success} shrink-0`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
          <path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      )
    case 'in_progress':
      return (
        <svg className={`h-5 w-5 ${textColors.brand} shrink-0 animate-spin`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
          <path strokeLinecap="round" strokeLinejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
      )
    case 'skipped':
      return (
        <svg className={`h-5 w-5 ${textColors.disabled} shrink-0`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
          <path strokeLinecap="round" strokeLinejoin="round" d="M20 12H4" />
        </svg>
      )
    default:
      return (
        <div className={`h-5 w-5 rounded-full border-2 ${borderColors.default} shrink-0`} />
      )
  }
}

export function MilestoneItem({ milestone }: MilestoneItemProps) {
  const isComplete = milestone.status === 'completed'

  return (
    <div className="flex items-start gap-3 py-2">
      <StatusIcon status={milestone.status} />
      <div className="flex-1 min-w-0">
        <p className={`text-sm font-medium ${isComplete ? textColors.disabled : textColors.primary}`}>
          {milestone.name}
        </p>
        <p className={`text-xs ${textColors.tertiary} mt-0.5`}>{milestone.description}</p>
        {milestone.status === 'in_progress' && (
          <div className="mt-1.5">
            <ProgressBar percent={milestone.progress_percent} size="sm" />
          </div>
        )}
      </div>
    </div>
  )
}
