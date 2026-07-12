import { useCallback } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { useRealtimeChannel } from '@/hooks/useRealtimeChannel'
import { kitchenKeys } from './useKitchenOrders'
import { usePosTenantScope } from './usePosTenantScope'

/**
 * Subscribe to the kitchen WebSocket channel for real-time KDS updates.
 *
 * Listens for:
 * - order.sent_to_kitchen → new order appears on KDS
 * - order.line_status_changed → line status updated
 * - order.ready → order fully ready
 *
 * On any event, invalidates the kitchen orders query cache.
 */
export function useKitchenChannel(): void {
  const queryClient = useQueryClient()
  const { tenantId, companyId } = usePosTenantScope()
  const channelName = tenantId && companyId
    ? `tenant.${tenantId}.company.${companyId}.pos.kitchen`
    : ''

  const handleEvent = useCallback(() => {
    void queryClient.invalidateQueries({ queryKey: [...kitchenKeys.orders()] })
  }, [queryClient])

  // Subscribe to each event type
  useRealtimeChannel({
    channelName,
    eventName: '.order.sent_to_kitchen',
    onEvent: handleEvent,
    isPrivate: true,
  })

  useRealtimeChannel({
    channelName,
    eventName: '.order.line_status_changed',
    onEvent: handleEvent,
    isPrivate: true,
  })

  useRealtimeChannel({
    channelName,
    eventName: '.order.ready',
    onEvent: handleEvent,
    isPrivate: true,
  })
}
