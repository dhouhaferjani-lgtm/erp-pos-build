import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
// §14.2 — `useCloseOrder` + `CloseOrderButton` imports removed: the
// order-close → SALE_RECEIPT path is retired. Order cancellation is the
// remaining termination path.
import { useSendToKitchen, useCancelOrder, useRemoveOrderLine } from '../../hooks/useOrders'
import { useMarkOrderServed } from '../../hooks/useKitchenOrders'
import { OrderLineItem } from '../../molecules/OrderLineItem'
import { OrderStatusBadge } from '../../molecules/OrderStatusBadge'
import { SendToKitchenButton } from '../../components/SendToKitchenButton'
import type { OrderData } from '../../api/orderApi'
import { Button } from '@/components/atoms'

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
  // §14.2 — `useCloseOrder()` removed; the order-close → SALE_RECEIPT
  // path is retired.
  const cancelOrder = useCancelOrder()
  const removeLine = useRemoveOrderLine()
  const markServed = useMarkOrderServed()

  const isOpen = order.status === 'open'
  const canSendToKitchen = isOpen && order.lines.length > 0
  const canCancel = ['open', 'sent_to_kitchen', 'ready'].includes(
    order.status
  )
  const canMarkServed = order.status === 'ready'

  const handleSendToKitchen = () => {
    sendToKitchen.mutate(order.id, {
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

  const handleMarkServed = () => {
    markServed.mutate(order.id, {
      onSuccess: () => onOrderUpdated?.(),
    })
  }

  return (
    <div className={cn('flex h-full flex-col rounded-xl border bg-white', borderColors.light)}>
      {/* Header */}
      <div className={cn('border-b p-4', borderColors.light)}>
        <div className="flex items-center justify-between">
          <div>
            <h2 className={cn('text-lg font-semibold', textColors.primary)}>
              {t('orders.orderNumber', { number: order.order_number })}
            </h2>
            {order.customer_name && (
              <p className={cn('text-sm', textColors.tertiary)}>
                {t('orders.detail.customer')}: {order.customer_name}
              </p>
            )}
          </div>
          <OrderStatusBadge status={order.status} />
        </div>

        <div className={cn('mt-2 flex flex-wrap gap-4 text-sm', textColors.tertiary)}>
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

        {/* Table info */}
        {order.table && (
          <div className="mt-2 flex items-center gap-2">
            <span className={cn(tokens.badge.base, tokens.badge.blue)}>
              {t('orders.detail.table')}: {order.table.table_number}
              {order.table.label ? ` - ${order.table.label}` : ''}
            </span>
            {order.table.floor_name && (
              <span className={cn('text-xs', textColors.disabled)}>
                ({order.table.floor_name})
              </span>
            )}
          </div>
        )}

        {/* Timing */}
        <div className={cn('mt-2 flex flex-wrap gap-4 text-xs', textColors.disabled)}>
          {order.ready_at && (
            <span>
              {t('orders.detail.readyAt')}: {new Date(order.ready_at).toLocaleTimeString()}
            </span>
          )}
          {order.served_at && (
            <span>
              {t('orders.detail.servedAt')}: {new Date(order.served_at).toLocaleTimeString()}
            </span>
          )}
        </div>

        {order.notes && (
          <p className={cn('mt-2 text-sm italic', textColors.disabled)}>
            {t('orders.detail.notes')}: {order.notes}
          </p>
        )}
      </div>

      {/* Lines */}
      <div className="flex-1 overflow-y-auto p-4">
        <h3 className={cn('mb-3 text-sm font-medium', textColors.secondary)}>
          {t('orders.detail.items')} ({order.lines.length})
        </h3>

        {order.lines.length === 0 ? (
          <p className={cn('text-center text-sm', textColors.disabled)}>
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
      <div className={cn('border-t p-4', borderColors.light)}>
        <div className="space-y-1 text-sm">
          <div className={cn('flex justify-between', textColors.tertiary)}>
            <span>{t('orders.detail.subtotal')}</span>
            <span className="tabular-nums">{order.subtotal} {order.currency}</span>
          </div>
          <div className={cn('flex justify-between', textColors.tertiary)}>
            <span>{t('orders.detail.tax')}</span>
            <span className="tabular-nums">{order.tax_amount} {order.currency}</span>
          </div>
          {parseFloat(order.discount_amount) > 0 && (
            <div className={cn('flex justify-between', textColors.error)}>
              <span>{t('orders.detail.discount')}</span>
              <span className="tabular-nums">-{order.discount_amount} {order.currency}</span>
            </div>
          )}
          <div className={cn('flex justify-between border-t pt-1 text-base font-bold', borderColors.light, textColors.primary)}>
            <span>{t('orders.detail.total')}</span>
            <span className="tabular-nums">{order.total} {order.currency}</span>
          </div>
        </div>
      </div>

      {/* Actions */}
      {(canCancel || canMarkServed) && (
        <div className={cn('flex flex-wrap items-center gap-2 border-t p-4', borderColors.light)}>
          {canSendToKitchen && (
            <SendToKitchenButton
              onConfirm={handleSendToKitchen}
              loading={sendToKitchen.isPending}
            />
          )}
          {canMarkServed && (
            <Button
              type="button"
              onClick={handleMarkServed}
              disabled={markServed.isPending}
            >
              {t('orders.actions.markServed')}
            </Button>
          )}
          {/* §14.2 — close-order affordance removed; the order-close →
              SALE_RECEIPT path is retired. Cancel remains for non-receipt
              order termination. */}
          {canCancel && (
            <Button variant="secondary"
              type="button"
              onClick={handleCancel}
              disabled={cancelOrder.isPending}
            >
              {t('orders.actions.cancelOrder')}
            </Button>
          )}
        </div>
      )}
    </div>
  )
}
