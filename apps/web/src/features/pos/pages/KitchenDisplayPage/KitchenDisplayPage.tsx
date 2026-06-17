import { useTranslation } from 'react-i18next'
import { useKitchenOrders, useUpdateLineStatus, useBumpOrder } from '../../hooks/useKitchenOrders'
import { useKitchenChannel } from '../../hooks/useKitchenChannel'
import { KitchenOrderCard } from '../../components/KitchenOrderCard'
import { colors, textColors } from '@/lib/designTokens'

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
      <div className={`flex h-screen items-center justify-center ${colors.neutral[100]}`}>
        <div className={`text-lg ${textColors.tertiary}`}>{t('common:loading', 'Loading...')}</div>
      </div>
    )
  }

  return (
    <div className={`flex h-screen flex-col ${colors.neutral[100]}`}>
      {/* Header */}
      <div className={`flex items-center justify-between ${colors.white} px-6 py-3 shadow-sm`}>
        <h1 className={`text-xl font-bold ${textColors.primary}`}>
          {t('kitchen.title')}
        </h1>
        <div className={`flex items-center gap-4 text-sm ${textColors.tertiary}`}>
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
              <p className={`text-2xl font-semibold ${textColors.disabled}`}>
                {t('kitchen.noOrders')}
              </p>
              <p className={`mt-2 text-sm ${textColors.disabled}`}>
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
