import { useTranslation } from 'react-i18next'
import type { WorkOrderStatus } from '../types'

interface TransitionBarProps {
  current: WorkOrderStatus
  canTransition: boolean
  canApprove: boolean
  canCancel: boolean
  canComplete: boolean
  onTransition: (to: WorkOrderStatus) => void
  onApprove: () => void
  onCancel: () => void
  onComplete: () => void
  isPending: boolean
}

/**
 * Adjacency map sourced from the backend StatusMachine (Spec §5.3). Kept in
 * sync manually in the absence of a generated status-machine DTO — add new
 * edges here AND in the PHP machine. Terminal states expose no onward edges.
 */
const ALLOWED_NEXT: Record<WorkOrderStatus, WorkOrderStatus[]> = {
  received: ['diagnosed'],
  diagnosed: ['quoted'],
  quoted: [],
  approved: ['in_progress', 'waiting_parts'],
  in_progress: ['paused', 'waiting_parts', 'completed'],
  paused: ['in_progress', 'waiting_parts'],
  waiting_parts: ['in_progress', 'paused'],
  completed: ['invoiced'],
  invoiced: ['closed'],
  closed: [],
  cancelled: [],
}

const ACTION_BUTTON =
  'inline-flex items-center gap-1 rounded-md bg-slate-900 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-slate-800 disabled:opacity-50 disabled:cursor-not-allowed'
const SECONDARY_BUTTON =
  'inline-flex items-center gap-1 rounded-md bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed'
const DANGER_BUTTON =
  'inline-flex items-center gap-1 rounded-md bg-rose-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-rose-500 disabled:opacity-50 disabled:cursor-not-allowed'

export function TransitionBar({
  current,
  canTransition,
  canApprove,
  canCancel,
  canComplete,
  onTransition,
  onApprove,
  onCancel,
  onComplete,
  isPending,
}: TransitionBarProps) {
  const { t } = useTranslation('workshop-work-orders')
  const nextStates = ALLOWED_NEXT[current]

  return (
    <div className="flex flex-wrap items-center gap-2">
      {current === 'quoted' && canApprove && (
        <button
          type="button"
          className={ACTION_BUTTON}
          onClick={onApprove}
          disabled={isPending}
        >
          {t('actions.approve')}
        </button>
      )}
      {current === 'in_progress' && canComplete && (
        <button
          type="button"
          className={ACTION_BUTTON}
          onClick={onComplete}
          disabled={isPending}
        >
          {t('actions.complete')}
        </button>
      )}
      {nextStates
        .filter(
          (next) =>
            // Primary "approve" button at top handles quoted→approved; skip secondary
            !(current === 'quoted' && next === 'approved') &&
            // Primary "complete" button (with mileage dialog) handles in_progress→completed; skip secondary
            !(current === 'in_progress' && next === 'completed')
        )
        .map((next) =>
          (next === 'approved' && canApprove) || (next !== 'approved' && canTransition) ? (
            <button
              key={next}
              type="button"
              className={SECONDARY_BUTTON}
              onClick={() => {
                onTransition(next)
              }}
              disabled={isPending}
            >
              {t(`actions.transitionTo.${next}`)}
            </button>
          ) : null
        )}
      {canCancel && current !== 'closed' && current !== 'cancelled' && (
        <button
          type="button"
          className={DANGER_BUTTON}
          onClick={onCancel}
          disabled={isPending}
        >
          {t('actions.cancel')}
        </button>
      )}
    </div>
  )
}
