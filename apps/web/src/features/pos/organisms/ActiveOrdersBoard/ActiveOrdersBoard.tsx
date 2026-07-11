import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { OrderStatusBadge } from '../../molecules/OrderStatusBadge'
import { KitchenTimer } from '../../atoms/KitchenTimer'
import { colors, textColors, borderColors, semanticColorTokens as colorTokens } from '@/lib/designTokens'
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
  /** Status-driven top accent: open → info, sent → warning, ready → success. */
  borderColor: string
}

const COLUMNS: BoardColumn[] = [
  {
    key: 'open',
    statuses: ['open'],
    labelKey: 'orders.board.open',
    borderColor: borderColors.primary,
  },
  {
    key: 'sent',
    statuses: ['sent_to_kitchen'],
    labelKey: 'orders.board.sent',
    borderColor: borderColors.warning,
  },
  {
    key: 'ready',
    statuses: ['ready'],
    labelKey: 'orders.board.ready',
    borderColor: borderColors.success,
  },
]

/**
 * Kanban-style board showing active orders in columns: Open, Sent, Ready.
 * Shows table number on cards when assigned. Color-coded timer for kitchen orders.
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
          className={`flex flex-col rounded-xl border-t-4 ${colors.neutral[50]} ${column.borderColor}`}
        >
          <div className="flex items-center justify-between px-4 py-3">
            <h3 className={`text-sm font-semibold ${textColors.secondary}`}>
              {t(column.labelKey)}
            </h3>
            <span className={`rounded-full ${colors.neutral[200]} px-2 py-0.5 text-xs font-medium ${textColors.tertiary}`}>
              {columnOrders[column.key].length}
            </span>
          </div>

          <div className="flex-1 space-y-2 overflow-y-auto px-3 pb-3">
            {columnOrders[column.key].length === 0 ? (
              <p className={`py-8 text-center text-sm ${textColors.disabled}`}>
                {t('orders.noOrders')}
              </p>
            ) : (
              columnOrders[column.key].map((order) => (
                <button
                  key={order.id}
                  type="button"
                  onClick={() => { onSelectOrder(order.id); }}
                  className={`w-full rounded-lg border p-3 text-left transition-colors ${colorTokens.variants.hoverBorderBlue500} ${
                    selectedOrderId === order.id
                      ? `${borderColors.primary} ${colors.primary[50]}`
                      : `${borderColors.light} ${colors.white}`
                  }`}
                >
                  <div className="flex items-center justify-between">
                    <span className={`text-sm font-bold ${textColors.primary}`}>
                      {order.order_number}
                    </span>
                    <OrderStatusBadge status={order.status} />
                  </div>

                  <div className="mt-1 flex items-center gap-2">
                    {order.table && (
                      <span className={`rounded ${colors.primary[100]} px-1.5 py-0.5 text-xs font-medium ${textColors.brand}`}>
                        {order.table.table_number}
                      </span>
                    )}
                    {order.customer_name && (
                      <span className={`text-xs ${textColors.tertiary}`}>
                        {order.customer_name}
                      </span>
                    )}
                  </div>

                  <div className={`mt-2 flex items-center justify-between text-xs ${textColors.disabled}`}>
                    <span>
                      {order.lines.length}{' '}
                      {order.lines.length === 1
                        ? t('cart.item')
                        : t('cart.items')}
                    </span>
                    {order.sent_at && (column.key === 'sent' || column.key === 'ready') ? (
                      <KitchenTimer startTime={order.sent_at} />
                    ) : (
                      <span>
                        {new Date(order.opened_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                      </span>
                    )}
                  </div>

                  <div className={`mt-1 text-right text-sm font-semibold tabular-nums ${textColors.primary}`}>
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
