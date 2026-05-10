import { useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useRealtimeChannel } from '../../../hooks/useRealtimeChannel'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { productsInvalidationPredicate } from './useProducts'

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
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { user } = useAuthStore()
  const getCurrentCompany = useCompanyStore((state) => state.getCurrentCompany)
  const currentCompany = getCurrentCompany()

  const tenantId = user?.tenant_id ?? null
  const companyId = currentCompany?.id ?? null

  const handleUpdate = useCallback(
    (data: ProductCostPriceUpdatePayload) => {
      // Invalidate the (potentially-orphan) `product` singular cache for this
      // productId — preserves prior intent. The plural `products` namespace
      // (used by productKeys.list / productKeys.detail) needs predicate-based
      // invalidation because tenantScopedKey() puts t/c at the suffix and the
      // wrapped `[products, t, c]` tag is NOT a prefix of the leaf
      // `[products, list, params, t, c]`.
      queryClient.invalidateQueries({
        queryKey: tenantScopedKey(['product', productId]),
      })
      queryClient.invalidateQueries({
        predicate: productsInvalidationPredicate(tenantId, companyId),
      })

      // Call custom callback if provided
      onUpdate?.(data)
    },
    [productId, queryClient, onUpdate, tenantId, companyId]
  )

  const handleError = useCallback((error: Error) => {
    console.error('WebSocket subscription error:', error)
    toast.error(t('common:errors.realtimeConnectionFailed'))
  }, [t])

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
