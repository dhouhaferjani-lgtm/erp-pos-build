import { useTranslation } from 'react-i18next'
import { KitchenTimer } from '../atoms/KitchenTimer'
import { OrderStatusBadge } from '../molecules/OrderStatusBadge'
import type { OrderData, OrderLineData } from '../api/orderApi'

interface KitchenOrderCardProps {
  order: OrderData
  onLineStatusChange: (orderId: string, lineId: string, newStatus: string) => void
  onBump: (orderId: string) => void
  isBumping: boolean
}

const LINE_STATUS_COLORS: Record<string, string> = {
  sent: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400',
  preparing: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
  ready: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
  cancelled: 'bg-red-100 text-red-800 line-through dark:bg-red-900/30 dark:text-red-400',
}

function getNextStatus(current: string): string | null {
  if (current === 'sent') return 'preparing'
  if (current === 'preparing') return 'ready'
  return null
}

/**
 * A single order card on the Kitchen Display, showing lines with tap-to-advance.
 */
export function KitchenOrderCard({
  order,
  onLineStatusChange,
  onBump,
  isBumping,
}: KitchenOrderCardProps) {
  const { t } = useTranslation('pos')

  const handleLineTap = (line: OrderLineData) => {
    const nextStatus = getNextStatus(line.status)
    if (nextStatus) {
      onLineStatusChange(order.id, line.id, nextStatus)
    }
  }

  const tableInfo = order.table as { table_number: string; label: string | null; floor_name: string | null } | undefined
  const hasPendingLines = order.lines.some(
    (l) => l.status === 'sent' || l.status === 'preparing'
  )

  return (
    <div className="flex flex-col rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
      {/* Header */}
      <div className="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
        <div className="flex items-center gap-3">
          <span className="text-lg font-bold text-gray-900 dark:text-gray-100">
            {order.order_number}
          </span>
          <OrderStatusBadge status={order.status} />
        </div>
        {order.sent_at && <KitchenTimer startTime={order.sent_at} />}
      </div>

      {/* Table + Mode info */}
      <div className="flex flex-wrap items-center gap-2 px-4 py-2 text-xs text-gray-500 dark:text-gray-400">
        {tableInfo && (
          <span className="rounded bg-blue-100 px-2 py-0.5 font-medium text-blue-800 dark:bg-blue-900/30 dark:text-blue-400">
            {tableInfo.table_number}
            {tableInfo.label ? ` - ${tableInfo.label}` : ''}
          </span>
        )}
        {order.consumption_mode && (
          <span className="rounded bg-gray-100 px-2 py-0.5 dark:bg-gray-700">
            {order.consumption_mode === 'SUR_PLACE'
              ? t('consumptionMode.dineIn')
              : t('consumptionMode.takeaway')}
          </span>
        )}
        {order.customer_name && (
          <span>{order.customer_name}</span>
        )}
      </div>

      {/* Lines */}
      <div className="flex-1 space-y-1 px-4 py-2">
        {order.lines.map((line) => {
          const nextStatus = getNextStatus(line.status)
          const canTap = nextStatus !== null

          return (
            <button
              key={line.id}
              type="button"
              disabled={!canTap}
              onClick={() => { handleLineTap(line) }}
              className={`flex w-full items-center justify-between rounded-lg px-3 py-2 text-left transition-colors ${
                canTap
                  ? 'hover:bg-gray-50 active:bg-gray-100 dark:hover:bg-gray-700 dark:active:bg-gray-600'
                  : ''
              }`}
            >
              <div className="flex-1">
                <div className="flex items-center gap-2">
                  <span className="font-medium text-gray-900 dark:text-gray-100">
                    {line.quantity}x
                  </span>
                  <span className="text-sm text-gray-800 dark:text-gray-200">
                    {line.product_name}
                  </span>
                </div>
                {line.special_instructions && (
                  <p className="mt-0.5 text-xs italic text-gray-500 dark:text-gray-400">
                    {line.special_instructions}
                  </p>
                )}
              </div>
              <span
                className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                  LINE_STATUS_COLORS[line.status] ?? 'bg-gray-100 text-gray-800'
                }`}
              >
                {t(`kitchen.lineStatus.${line.status}`)}
              </span>
            </button>
          )
        })}
      </div>

      {/* Actions */}
      {hasPendingLines && (
        <div className="border-t border-gray-200 px-4 py-3 dark:border-gray-700">
          <button
            type="button"
            onClick={() => { onBump(order.id) }}
            disabled={isBumping}
            className="w-full rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-green-700 disabled:opacity-50"
          >
            {t('kitchen.bump')}
          </button>
        </div>
      )}
    </div>
  )
}
