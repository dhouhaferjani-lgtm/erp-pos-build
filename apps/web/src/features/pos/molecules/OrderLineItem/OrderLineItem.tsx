import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { textColors, borderColors, colors } from '@/lib/designTokens'
import { StatusBadge, statusTone, type StatusTone } from '@/components/atoms/StatusBadge'
import type { OrderLineData } from '../../api/orderApi'

export interface OrderLineItemProps {
  line: OrderLineData
  editable: boolean
  onModify?: (lineId: string) => void
  onRemove?: (lineId: string) => void
}

/**
 * Domain line-status → semantic tone overrides for the status pill.
 * `sent`/`preparing` map to `warning`, `served` to `info`, `ready` to
 * `success`, `cancelled` to `danger`; everything else falls back to neutral.
 */
const lineStatusToneOverrides: Record<string, StatusTone> = {
  pending: 'neutral',
  sent: 'warning',
  preparing: 'warning',
  ready: 'success',
  served: 'info',
  cancelled: 'danger',
}

/**
 * Single order line display with edit/remove controls.
 */
export function OrderLineItem({
  line,
  editable,
  onModify,
  onRemove,
}: OrderLineItemProps) {
  const { t } = useTranslation('pos')

  const tone = statusTone(line.status, lineStatusToneOverrides)

  return (
    <div className={cn('flex items-center justify-between gap-3 rounded-lg border p-3', borderColors.light)}>
      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2">
          <span className={cn('font-medium', textColors.primary)}>
            {line.product_name}
          </span>
          <StatusBadge
            tone={tone}
            className={cn(line.status === 'cancelled' && 'line-through')}
          >
            {t(`orders.lineStatus.${line.status}`)}
          </StatusBadge>
        </div>

        <div className={cn('mt-1 flex items-center gap-3 text-sm', textColors.tertiary)}>
          <span>
            {t('orders.quantity')}: <span className="tabular-nums">{line.quantity}</span>
          </span>
          <span className="tabular-nums">@ {line.unit_price}</span>
          {parseFloat(line.discount_amount) > 0 && (
            <span className={cn('tabular-nums', textColors.error)}>
              -{line.discount_amount}
            </span>
          )}
        </div>

        {line.special_instructions && (
          <p className={cn('mt-1 text-xs italic', textColors.disabled)}>
            {line.special_instructions}
          </p>
        )}
      </div>

      <div className="flex items-center gap-2">
        <span className={cn('whitespace-nowrap font-semibold tabular-nums', textColors.primary)}>
          {line.line_total}
        </span>

        {editable && (
          <div className="flex gap-1">
            {onModify && (
              <button
                type="button"
                onClick={() => { onModify(line.id); }}
                className={cn('rounded p-1 text-sm', textColors.brand, colors.hover.gray100)}
              >
                {t('orders.actions.modifyLine')}
              </button>
            )}
            {onRemove && (
              <button
                type="button"
                onClick={() => { onRemove(line.id); }}
                className={cn('rounded p-1 text-sm', textColors.error, colors.hover.red50)}
              >
                {t('orders.actions.removeLine')}
              </button>
            )}
          </div>
        )}
      </div>
    </div>
  )
}
