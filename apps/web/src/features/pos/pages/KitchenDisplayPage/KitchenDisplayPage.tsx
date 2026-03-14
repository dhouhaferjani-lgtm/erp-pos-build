import { useTranslation } from 'react-i18next'
import { useKitchenOrders, useUpdateLineStatus, useBumpOrder } from '../../hooks/useKitchenOrders'
import { useKitchenChannel } from '../../hooks/useKitchenChannel'
import { KitchenOrderCard } from '../../components/KitchenOrderCard'

/**
 * Full-screen Kitchen Display System page.
 * Shows active orders as cards sorted by sent_at (oldest first).
 * Real-time updates via WebSocket with 30s polling fallback.
 */
export function KitchenDisplayPage() {
  const { t } = useTranslation('pos')
  const { data: orders, isLoading } = useKitchenOrders()
  const updateLineStatus = useUpdateLineStatus()
  const bumpOrder = useBumpOrder()

  // Subscribe to real-time kitchen events
  useKitchenChannel()

  const handleLineStatusChange = (orderId: string, lineId: string, newStatus: string) => {
    updateLineStatus.mutate({ orderId, lineId, status: newStatus })
  }

  const handleBump = (orderId: string) => {
    bumpOrder.mutate(orderId)
  }

  if (isLoading) {
    return (
      <div className="flex h-screen items-center justify-center bg-gray-100 dark:bg-gray-900">
        <div className="text-lg text-gray-500">{t('common:loading', 'Loading...')}</div>
      </div>
    )
  }

  return (
    <div className="flex h-screen flex-col bg-gray-100 dark:bg-gray-900">
      {/* Header */}
      <div className="flex items-center justify-between bg-white px-6 py-3 shadow-sm dark:bg-gray-800">
        <h1 className="text-xl font-bold text-gray-900 dark:text-gray-100">
          {t('kitchen.title')}
        </h1>
        <div className="flex items-center gap-4 text-sm text-gray-500 dark:text-gray-400">
          <span>
            {orders?.length ?? 0} {t('kitchen.activeOrders')}
          </span>
          <span className="text-xs">
            {new Date().toLocaleTimeString()}
          </span>
        </div>
      </div>

      {/* Orders Grid */}
      <div className="flex-1 overflow-y-auto p-6">
        {!orders || orders.length === 0 ? (
          <div className="flex h-full items-center justify-center">
            <div className="text-center">
              <p className="text-2xl font-semibold text-gray-400 dark:text-gray-500">
                {t('kitchen.noOrders')}
              </p>
              <p className="mt-2 text-sm text-gray-400 dark:text-gray-500">
                {t('kitchen.waitingForOrders')}
              </p>
            </div>
          </div>
        ) : (
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            {orders.map((order) => (
              <KitchenOrderCard
                key={order.id}
                order={order}
                onLineStatusChange={handleLineStatusChange}
                onBump={handleBump}
                isBumping={bumpOrder.isPending}
              />
            ))}
          </div>
        )}
      </div>
    </div>
  )
}
