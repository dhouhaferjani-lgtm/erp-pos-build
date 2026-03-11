import { useTranslation } from 'react-i18next'
import type { OrderLineData } from '../../api/orderApi'

export interface OrderLineItemProps {
  line: OrderLineData
  editable: boolean
  onModify?: (lineId: string) => void
  onRemove?: (lineId: string) => void
}

const lineStatusColors: Record<string, string> = {
  pending: 'text-gray-500',
  sent: 'text-amber-600',
  preparing: 'text-orange-600',
  ready: 'text-green-600',
  served: 'text-blue-600',
  cancelled: 'text-red-600 line-through',
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

  const statusColor = lineStatusColors[line.status] ?? 'text-gray-500'

  return (
    <div className="flex items-center justify-between gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2">
          <span className="font-medium text-gray-900 dark:text-gray-100">
            {line.product_name}
          </span>
          <span className={`text-xs font-medium ${statusColor}`}>
            {t(`orders.lineStatus.${line.status}`)}
          </span>
        </div>

        <div className="mt-1 flex items-center gap-3 text-sm text-gray-500 dark:text-gray-400">
          <span>
            {t('orders.quantity')}: {line.quantity}
          </span>
          <span>@ {line.unit_price}</span>
          {parseFloat(line.discount_amount) > 0 && (
            <span className="text-red-600">
              -{line.discount_amount}
            </span>
          )}
        </div>

        {line.special_instructions && (
          <p className="mt-1 text-xs italic text-gray-400 dark:text-gray-500">
            {line.special_instructions}
          </p>
        )}
      </div>

      <div className="flex items-center gap-2">
        <span className="whitespace-nowrap font-semibold text-gray-900 dark:text-gray-100">
          {line.line_total}
        </span>

        {editable && (
          <div className="flex gap-1">
            {onModify && (
              <button
                type="button"
                onClick={() => onModify(line.id)}
                className="rounded p-1 text-sm text-blue-600 hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-blue-900/30"
              >
                {t('orders.actions.modifyLine')}
              </button>
            )}
            {onRemove && (
              <button
                type="button"
                onClick={() => onRemove(line.id)}
                className="rounded p-1 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30"
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
