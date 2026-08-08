import { useTranslation } from 'react-i18next'
import { StatusBadge } from '@/components/atoms/StatusBadge/StatusBadge'
import { statusTone } from '@/components/atoms/StatusBadge/statusTone'
import type { StockAdjustmentStatus } from '../types'

/**
 * The status pill.
 *
 * Tone comes from the shared `statusTone()` map, not a local switch — a local
 * status→colour map inside a feature is exactly what the design-system audit's
 * C6 rule exists to catch, and it is how two screens end up disagreeing about
 * what "posted" looks like. Only `posted` needs an override: `draft` and
 * `cancelled` are already in the shared map.
 *
 * The label is a TEMPLATE key, so a status added on the backend surfaces as a
 * missing translation rather than as a raw enum value in the UI.
 */
export function StockAdjustmentStatusBadge({ status }: { status: StockAdjustmentStatus }) {
  const { t } = useTranslation('stock-adjustments')

  return (
    <StatusBadge tone={statusTone(status, { posted: 'success' })}>
      {t(`status.${status}`)}
    </StatusBadge>
  )
}
