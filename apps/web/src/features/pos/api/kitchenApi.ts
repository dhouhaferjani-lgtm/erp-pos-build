import { apiGet, apiPatch, apiPost } from '@/lib/api'
import type { OrderData } from './orderApi'

// ─── Types ───────────────────────────────────────────────────────────────────

export interface KitchenLineUpdateResponse {
  line: {
    id: string
    status: string
    prepared_at: string | null
  }
  order: OrderData
}

// ─── API Functions ───────────────────────────────────────────────────────────

/**
 * Fetch active kitchen orders (sent_to_kitchen + ready).
 */
export async function getKitchenOrders(): Promise<OrderData[]> {
  return apiGet<OrderData[]>('/pos/kitchen/orders')
}

/**
 * Update a single line status on a kitchen order.
 */
export async function updateLineStatus(
  orderId: string,
  lineId: string,
  status: string
): Promise<KitchenLineUpdateResponse> {
  return apiPatch<KitchenLineUpdateResponse>(
    `/pos/kitchen/orders/${orderId}/lines/${lineId}/status`,
    { status }
  )
}

/**
 * Bump all lines on an order to Ready.
 */
export async function bumpOrder(orderId: string): Promise<OrderData> {
  return apiPost<OrderData>(`/pos/kitchen/orders/${orderId}/bump`)
}

/**
 * Mark an order as served.
 */
export async function markOrderServed(orderId: string): Promise<OrderData> {
  return apiPost<OrderData>(`/pos/orders/${orderId}/served`)
}
