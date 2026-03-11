import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { OrderStatusBadge } from '../../molecules/OrderStatusBadge'
import type { OrderData } from '../../api/orderApi'

export interface ActiveOrdersBoardProps {
  orders: OrderData[]
  onSelectOrder: (orderId: string) => void
  selectedOrderId?: string | undefined
}

interface BoardColumn {
  key: string
  statuses: string[]
  labelKey: string
  borderColor: string
}

const COLUMNS: BoardColumn[] = [
  {
    key: 'open',
    statuses: ['open'],
    labelKey: 'orders.board.open',
    borderColor: 'border-blue-300 dark:border-blue-700',
  },
  {
    key: 'sent',
    statuses: ['sent_to_kitchen'],
    labelKey: 'orders.board.sent',
    borderColor: 'border-amber-300 dark:border-amber-700',
  },
  {
    key: 'ready',
    statuses: ['ready'],
    labelKey: 'orders.board.ready',
    borderColor: 'border-green-300 dark:border-green-700',
  },
]

/**
 * Calculate elapsed time since a date as a human-readable string.
 */
function getTimeSince(dateStr: string): string {
  const now = Date.now()
  const then = new Date(dateStr).getTime()
  const diffMs = now - then

  const minutes = Math.floor(diffMs / 60000)
  if (minutes < 1) return '<1m'
  if (minutes < 60) return `${minutes}m`

  const hours = Math.floor(minutes / 60)
  const remainingMinutes = minutes % 60
  return `${hours}h${remainingMinutes > 0 ? ` ${remainingMinutes}m` : ''}`
}

/**
 * Kanban-style board showing active orders in columns: Open, Sent, Ready.
 */
export function ActiveOrdersBoard({
  orders,
  onSelectOrder,
  selectedOrderId,
}: ActiveOrdersBoardProps) {
  const { t } = useTranslation('pos')

  const columnOrders = useMemo(() => {
    const grouped: Record<string, OrderData[]> = {}
    for (const col of COLUMNS) {
      grouped[col.key] = orders.filter((o) => col.statuses.includes(o.status))
    }
    return grouped
  }, [orders])

  return (
    <div className="grid h-full grid-cols-3 gap-4">
      {COLUMNS.map((column) => (
        <div
          key={column.key}
          className={`flex flex-col rounded-xl border-t-4 bg-gray-50 dark:bg-gray-900 ${column.borderColor}`}
        >
          <div className="flex items-center justify-between px-4 py-3">
            <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300">
              {t(column.labelKey)}
            </h3>
            <span className="rounded-full bg-gray-200 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-400">
              {columnOrders[column.key].length}
            </span>
          </div>

          <div className="flex-1 space-y-2 overflow-y-auto px-3 pb-3">
            {columnOrders[column.key].length === 0 ? (
              <p className="py-8 text-center text-sm text-gray-400 dark:text-gray-600">
                {t('orders.noOrders')}
              </p>
            ) : (
              columnOrders[column.key].map((order) => (
                <button
                  key={order.id}
                  type="button"
                  onClick={() => onSelectOrder(order.id)}
                  className={`w-full rounded-lg border p-3 text-left transition-colors hover:border-blue-300 dark:hover:border-blue-600 ${
                    selectedOrderId === order.id
                      ? 'border-blue-500 bg-blue-50 dark:border-blue-500 dark:bg-blue-900/20'
                      : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800'
                  }`}
                >
                  <div className="flex items-center justify-between">
                    <span className="text-sm font-bold text-gray-900 dark:text-gray-100">
                      {order.order_number}
                    </span>
                    <OrderStatusBadge status={order.status} />
                  </div>

                  {order.customer_name && (
                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                      {order.customer_name}
                    </p>
                  )}

                  <div className="mt-2 flex items-center justify-between text-xs text-gray-400 dark:text-gray-500">
                    <span>
                      {order.lines.length}{' '}
                      {order.lines.length === 1
                        ? t('cart.item')
                        : t('cart.items')}
                    </span>
                    <span>{getTimeSince(order.opened_at)}</span>
                  </div>

                  <div className="mt-1 text-right text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {order.total} {order.currency}
                  </div>
                </button>
              ))
            )}
          </div>
        </div>
      ))}
    </div>
  )
}
