import { useCallback } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { useRealtimeChannel } from '../../../hooks/useRealtimeChannel'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'

/**
 * Product Cost Price Update payload from WebSocket.
 * Matches the backend ProductCostPriceUpdatedBroadcast payload.
 */
export interface ProductCostPriceUpdatePayload {
  productId: string
  productSku: string
  oldCostPrice: string
  newCostPrice: string
  oldSalePrice: string
  newSalePrice: string
  reason: string
  timestamp: string
  referenceDocument?: string
}

export interface UseProductRealtimeOptions {
  /** Product ID to subscribe to */
  productId: string
  /** Callback when product cost/price is updated */
  onUpdate?: (data: ProductCostPriceUpdatePayload) => void
  /** Whether to enable real-time updates (default: true) */
  enabled?: boolean
}

/**
 * Hook for subscribing to real-time product cost/price updates.
 *
 * Automatically invalidates product queries when updates are received,
 * ensuring the UI stays in sync with backend changes.
 *
 * @example
 * ```tsx
 * const ProductDetailPage = ({ productId }) => {
 *   useProductRealtime({
 *     productId,
 *     onUpdate: (data) => {
 *       toast.success(`Cost updated: ${data.newCostPrice}`)
 *     },
 *   })
 *
 *   // Product data will automatically refresh when cost changes
 *   const { data: product } = useProduct(productId)
 *   // ...
 * }
 * ```
 */
export function useProductRealtime(options: UseProductRealtimeOptions): void {
  const { productId, onUpdate, enabled = true } = options
  const queryClient = useQueryClient()
  const { user } = useAuthStore()
  const getCurrentCompany = useCompanyStore((state) => state.getCurrentCompany)
  const currentCompany = getCurrentCompany()

  const handleUpdate = useCallback(
    (data: ProductCostPriceUpdatePayload) => {
      // Invalidate product queries to trigger refetch
      queryClient.invalidateQueries({
        queryKey: ['product', productId],
      })

      // Also invalidate product list queries in case product is in a list
      queryClient.invalidateQueries({
        queryKey: ['products'],
      })

      // Call custom callback if provided
      onUpdate?.(data)
    },
    [productId, queryClient, onUpdate]
  )

  const handleError = useCallback((error: Error) => {
    console.error('WebSocket subscription error:', error)
    // In production, you might want to report this to an error tracking service
  }, [])

  // Only subscribe if we have the required auth context
  const shouldSubscribe = Boolean(
    enabled && user && currentCompany && productId
  )

  // Subscribe to product channel
  useRealtimeChannel<ProductCostPriceUpdatePayload>({
    channelName: shouldSubscribe
      ? `tenant.${user!.tenant_id}.company.${currentCompany!.id}.product.${productId}`
      : '',
    eventName: 'product.cost-price-updated',
    onEvent: shouldSubscribe ? handleUpdate : () => {},
    onError: handleError,
    isPrivate: true,
  })
}
