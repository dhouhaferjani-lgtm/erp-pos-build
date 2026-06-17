import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { useOrders } from '../../hooks/useOrders'
import { ActiveOrdersBoard } from '../../organisms/ActiveOrdersBoard'
import { OrderPanel } from '../../organisms/OrderPanel'
import type { OrderData } from '../../api/orderApi'
import { textColors, borderColors } from '@/lib/designTokens'

export interface OrdersPageProps {
  terminalId?: string
  shiftId?: string
}

/**
 * Active orders view with Kanban board and detail panel.
 */
export function OrdersPage({ terminalId, shiftId }: OrdersPageProps) {
  const { t } = useTranslation('pos')
  const [selectedOrderId, setSelectedOrderId] = useState<string | undefined>()
  const [statusFilter, setStatusFilter] = useState<string>('')

  const orderParams = useMemo(() => {
    const params: Record<string, unknown> = {
      active: !statusFilter,
      per_page: 100,
    }
    if (terminalId) params['terminal_id'] = terminalId
    if (shiftId) params['shift_id'] = shiftId
    if (statusFilter) params['status'] = statusFilter
    return params as import('../../api/orderApi').OrderListParams
  }, [terminalId, shiftId, statusFilter])

  const { data, isLoading, refetch } = useOrders(orderParams)

  const orders: OrderData[] = useMemo(() => {
    if (!data) return []
    // Handle both wrapped and unwrapped response shapes
    if (Array.isArray(data)) return data
    if ('data' in data && Array.isArray(data.data)) return data.data
    return []
  }, [data])

  const selectedOrder = useMemo(
    () => orders.find((o) => o.id === selectedOrderId),
    [orders, selectedOrderId]
  )

  const handleOrderUpdated = () => {
    refetch()
  }

  if (isLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <p className={textColors.tertiary}>
          {t('products.loading')}
        </p>
      </div>
    )
  }

  return (
    <div className="flex h-full flex-col">
      {/* Header */}
      <div className={`flex items-center justify-between border-b ${borderColors.light} px-6 py-4`}>
        <h1 className={`text-xl font-bold ${textColors.primary}`}>
          {t('orders.title')}
        </h1>

        <div className="flex items-center gap-3">
          <select
            value={statusFilter}
            onChange={(e) => { setStatusFilter(e.target.value); }}
            className={`rounded-lg border ${borderColors.default} px-3 py-1.5 text-sm`}
          >
            <option value="">{t('orders.filters.allStatuses')}</option>
            <option value="open">{t('orders.status.open')}</option>
            <option value="sent_to_kitchen">
              {t('orders.status.sent_to_kitchen')}
            </option>
            <option value="ready">{t('orders.status.ready')}</option>
            <option value="closed">{t('orders.status.closed')}</option>
            <option value="cancelled">{t('orders.status.cancelled')}</option>
          </select>
        </div>
      </div>

      {/* Content */}
      {orders.length === 0 ? (
        <div className="flex flex-1 flex-col items-center justify-center gap-2">
          <p className={`text-lg font-medium ${textColors.tertiary}`}>
            {t('orders.noOrders')}
          </p>
          <p className={`text-sm ${textColors.disabled}`}>
            {t('orders.noOrdersDescription')}
          </p>
        </div>
      ) : (
        <div className="flex flex-1 overflow-hidden">
          {/* Kanban Board */}
          <div className="flex-1 overflow-auto p-4">
            <ActiveOrdersBoard
              orders={orders}
              onSelectOrder={(id: string) => { setSelectedOrderId(id); }}
              selectedOrderId={selectedOrderId}
            />
          </div>

          {/* Detail Panel */}
          {selectedOrder && (
            <div className={`w-96 border-l ${borderColors.light}`}>
              <OrderPanel
                order={selectedOrder}
                onOrderUpdated={handleOrderUpdated}
              />
            </div>
          )}
        </div>
      )}
    </div>
  )
}
