import { useTranslation } from 'react-i18next'
import { useSendToKitchen, useCloseOrder, useCancelOrder, useRemoveOrderLine } from '../../hooks/useOrders'
import { OrderLineItem } from '../../molecules/OrderLineItem'
import { OrderStatusBadge } from '../../molecules/OrderStatusBadge'
import { SendToKitchenButton } from '../../components/SendToKitchenButton'
import { CloseOrderButton } from '../../components/CloseOrderButton'
import type { OrderData } from '../../api/orderApi'

export interface OrderPanelProps {
  order: OrderData
  onLineModify?: (lineId: string) => void
  onOrderUpdated?: () => void
}

/**
 * Order detail/edit panel showing order info, lines, and action buttons.
 */
export function OrderPanel({
  order,
  onLineModify,
  onOrderUpdated,
}: OrderPanelProps) {
  const { t } = useTranslation('pos')

  const sendToKitchen = useSendToKitchen()
  const closeOrder = useCloseOrder()
  const cancelOrder = useCancelOrder()
  const removeLine = useRemoveOrderLine()

  const isOpen = order.status === 'open'
  const canSendToKitchen = isOpen && order.lines.length > 0
  const canClose =
    ['open', 'sent_to_kitchen', 'ready'].includes(order.status) &&
    order.lines.length > 0
  const canCancel = ['open', 'sent_to_kitchen', 'ready'].includes(
    order.status
  )

  const handleSendToKitchen = () => {
    sendToKitchen.mutate(order.id, {
      onSuccess: () => onOrderUpdated?.(),
    })
  }

  const handleClose = () => {
    closeOrder.mutate(order.id, {
      onSuccess: () => onOrderUpdated?.(),
    })
  }

  const handleCancel = () => {
    cancelOrder.mutate(
      { orderId: order.id },
      { onSuccess: () => onOrderUpdated?.() }
    )
  }

  const handleRemoveLine = (lineId: string) => {
    removeLine.mutate(
      { orderId: order.id, lineId },
      { onSuccess: () => onOrderUpdated?.() }
    )
  }

  return (
    <div className="flex h-full flex-col rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
      {/* Header */}
      <div className="border-b border-gray-200 p-4 dark:border-gray-700">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-lg font-semibold text-gray-900 dark:text-gray-100">
              {t('orders.orderNumber', { number: order.order_number })}
            </h2>
            {order.customer_name && (
              <p className="text-sm text-gray-500 dark:text-gray-400">
                {t('orders.detail.customer')}: {order.customer_name}
              </p>
            )}
          </div>
          <OrderStatusBadge status={order.status} />
        </div>

        <div className="mt-2 flex flex-wrap gap-4 text-sm text-gray-500 dark:text-gray-400">
          <span>
            {t('orders.detail.cashier')}: {order.cashier_name}
          </span>
          <span>
            {t('orders.detail.openedAt')}:{' '}
            {new Date(order.opened_at).toLocaleTimeString()}
          </span>
          {order.consumption_mode && (
            <span>
              {t('orders.detail.consumption')}: {order.consumption_mode}
            </span>
          )}
        </div>

        {order.notes && (
          <p className="mt-2 text-sm italic text-gray-400 dark:text-gray-500">
            {t('orders.detail.notes')}: {order.notes}
          </p>
        )}
      </div>

      {/* Lines */}
      <div className="flex-1 overflow-y-auto p-4">
        <h3 className="mb-3 text-sm font-medium text-gray-700 dark:text-gray-300">
          {t('orders.detail.items')} ({order.lines.length})
        </h3>

        {order.lines.length === 0 ? (
          <p className="text-center text-sm text-gray-400 dark:text-gray-500">
            {t('orders.noOrders')}
          </p>
        ) : (
          <div className="space-y-2">
            {order.lines.map((line) => (
              <OrderLineItem
                key={line.id}
                line={line}
                editable={isOpen}
                {...(onLineModify ? { onModify: onLineModify } : {})}
                onRemove={handleRemoveLine}
              />
            ))}
          </div>
        )}
      </div>

      {/* Totals */}
      <div className="border-t border-gray-200 p-4 dark:border-gray-700">
        <div className="space-y-1 text-sm">
          <div className="flex justify-between text-gray-500 dark:text-gray-400">
            <span>{t('orders.detail.subtotal')}</span>
            <span>{order.subtotal} {order.currency}</span>
          </div>
          <div className="flex justify-between text-gray-500 dark:text-gray-400">
            <span>{t('orders.detail.tax')}</span>
            <span>{order.tax_amount} {order.currency}</span>
          </div>
          {parseFloat(order.discount_amount) > 0 && (
            <div className="flex justify-between text-red-600">
              <span>{t('orders.detail.discount')}</span>
              <span>-{order.discount_amount} {order.currency}</span>
            </div>
          )}
          <div className="flex justify-between border-t border-gray-100 pt-1 text-base font-bold text-gray-900 dark:border-gray-600 dark:text-gray-100">
            <span>{t('orders.detail.total')}</span>
            <span>{order.total} {order.currency}</span>
          </div>
        </div>
      </div>

      {/* Actions */}
      {canCancel && (
        <div className="flex flex-wrap items-center gap-2 border-t border-gray-200 p-4 dark:border-gray-700">
          {canSendToKitchen && (
            <SendToKitchenButton
              onConfirm={handleSendToKitchen}
              loading={sendToKitchen.isPending}
            />
          )}
          {canClose && (
            <CloseOrderButton
              onConfirm={handleClose}
              loading={closeOrder.isPending}
            />
          )}
          <button
            type="button"
            onClick={handleCancel}
            disabled={cancelOrder.isPending}
            className="rounded-lg border border-red-300 px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50 disabled:opacity-50 dark:border-red-700 dark:text-red-400 dark:hover:bg-red-900/20"
          >
            {t('orders.actions.cancelOrder')}
          </button>
        </div>
      )}
    </div>
  )
}
