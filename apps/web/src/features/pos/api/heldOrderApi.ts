import { apiGet, apiPost, apiDelete } from '@/lib/api'

/**
 * Cart snapshot line item stored in held order.
 */
export interface CartSnapshotLine {
  product_id: string
  product_name: string
  variant_name?: string | null
  quantity: number
  unit_price: string
  discount_amount: string
  tax_rate: string
  modifiers: unknown[]
  special_instructions: string | null
}

/**
 * Full cart snapshot stored with held order.
 */
export interface CartSnapshot {
  lines: CartSnapshotLine[]
  customer: {
    partner_id: string | null
    name: string
    identifier: string | null
  } | null
  consumption_mode: string | null
  notes: string | null
  discount: {
    type: 'percentage' | 'fixed'
    value: string
  } | null
}

/**
 * Held order data returned from the API.
 */
export interface HeldOrderData {
  id: string
  terminal_id: string
  shift_id: string
  cashier_id: string
  label: string | null
  cart_snapshot: CartSnapshot
  status: string
  held_at: string
  expires_at: string | null
  recalled_at: string | null
  line_count: number
  total: string | null
  created_at: string
}

/**
 * Request payload for holding an order.
 */
export interface HoldOrderRequest {
  terminal_id: string
  shift_id: string
  label?: string | null
  cart_snapshot: CartSnapshot
  expires_in_minutes?: number | null
}

/**
 * Hold the current cart as a new held order.
 *
 * POST /pos/held-orders
 */
export async function holdOrder(data: HoldOrderRequest): Promise<HeldOrderData> {
  return apiPost<HeldOrderData>('/pos/held-orders', data)
}

/**
 * List held orders for a terminal, optionally filtered by shift.
 *
 * GET /pos/held-orders?terminal_id=xxx&shift_id=yyy
 */
export async function getHeldOrders(
  terminalId: string,
  shiftId?: string,
): Promise<HeldOrderData[]> {
  const params: Record<string, unknown> = { terminal_id: terminalId }
  if (shiftId) {
    params['shift_id'] = shiftId
  }
  return apiGet<HeldOrderData[]>('/pos/held-orders', params)
}

/**
 * Get a single held order by ID.
 *
 * GET /pos/held-orders/:id
 */
export async function getHeldOrder(id: string): Promise<HeldOrderData> {
  return apiGet<HeldOrderData>(`/pos/held-orders/${id}`)
}

/**
 * Recall a held order back to the cart.
 *
 * POST /pos/held-orders/:id/recall
 */
export async function recallHeldOrder(id: string): Promise<HeldOrderData> {
  return apiPost<HeldOrderData>(`/pos/held-orders/${id}/recall`)
}

/**
 * Discard (permanently delete) a held order.
 *
 * DELETE /pos/held-orders/:id
 */
export async function discardHeldOrder(id: string): Promise<void> {
  await apiDelete<{ success: boolean }>(`/pos/held-orders/${id}`)
}
