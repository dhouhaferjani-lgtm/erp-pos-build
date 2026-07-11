import { useTranslation } from 'react-i18next'
import { Button } from '@/components/atoms'
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

// The transition controls render at the Button atom's `xs` size (text-xs +
// px-3 py-1.5); `gap-1 shadow-sm` are layout extras carried via className.
const BUTTON_LAYOUT = 'gap-1 shadow-sm'

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
        <Button
          type="button"
          variant="primary"
          size="xs"
          className={BUTTON_LAYOUT}
          onClick={onApprove}
          disabled={isPending}
        >
          {t('actions.approve')}
        </Button>
      )}
      {current === 'in_progress' && canComplete && (
        <Button
          type="button"
          variant="primary"
          size="xs"
          className={BUTTON_LAYOUT}
          onClick={onComplete}
          disabled={isPending}
        >
          {t('actions.complete')}
        </Button>
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
            <Button
              key={next}
              type="button"
              variant="secondary"
              size="xs"
              className={BUTTON_LAYOUT}
              onClick={() => {
                onTransition(next)
              }}
              disabled={isPending}
            >
              {t(`actions.transitionTo.${next}`)}
            </Button>
          ) : null
        )}
      {canCancel && current !== 'closed' && current !== 'cancelled' && (
        <Button
          type="button"
          variant="danger"
          size="xs"
          className={BUTTON_LAYOUT}
          onClick={onCancel}
          disabled={isPending}
        >
          {t('actions.cancel')}
        </Button>
      )}
    </div>
  )
}
