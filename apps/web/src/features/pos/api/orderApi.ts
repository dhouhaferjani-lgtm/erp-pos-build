import { apiGet, apiPost, apiPatch, apiDelete } from '@/lib/api'

// ─── Types ───────────────────────────────────────────────────────────────────

export interface OrderLineData {
  id: string
  order_id: string
  line_number: number
  product_id: string
  product_name: string
  variant_name: string | null
  barcode: string | null
  quantity: string
  unit_price: string
  discount_amount: string
  tax_rate: string
  tax_amount: string
  line_total: string
  modifiers: Record<string, unknown> | null
  special_instructions: string | null
  status: 'pending' | 'sent' | 'preparing' | 'ready' | 'served' | 'cancelled'
  sent_at: string | null
  prepared_at: string | null
  created_at: string
}

export interface OrderTableData {
  id: string
  table_number: string
  label: string | null
  floor_name: string | null
}

export interface OrderData {
  id: string
  terminal_id: string
  shift_id: string
  table_id: string | null
  order_number: string
  status: 'open' | 'sent_to_kitchen' | 'ready' | 'closed' | 'cancelled'
  cashier_id: string
  cashier_name: string
  customer_name: string | null
  customer_identifier: string | null
  partner_id: string | null
  subtotal: string
  tax_amount: string
  discount_amount: string
  total: string
  currency: string
  consumption_mode: string | null
  notes: string | null
  opened_at: string
  sent_at: string | null
  ready_at: string | null
  served_at: string | null
  closed_at: string | null
  cancelled_at: string | null
  receipt_id: string | null
  table?: OrderTableData
  lines: OrderLineData[]
}

export interface OrderListResponse {
  data: OrderData[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    timestamp: string
  }
}

export interface CreateOrderRequest {
  terminal_id: string
  shift_id: string
  table_id?: string
  partner_id?: string
  customer_name?: string
  consumption_mode?: string
  notes?: string
}

export interface AddOrderLineRequest {
  product_id: string
  quantity: number
  unit_price: string
  tax_rate: string
  discount_amount?: string
  modifiers?: Record<string, unknown>[]
  special_instructions?: string
}

export interface ModifyOrderLineRequest {
  quantity?: number
  discount_amount?: string
  modifiers?: Record<string, unknown>[]
  special_instructions?: string
}

export interface AddLineResponse {
  line: OrderLineData
  order: OrderData
}

export interface ModifyLineResponse {
  line: OrderLineData
  order: OrderData
}

export interface OrderListParams {
  terminal_id?: string
  shift_id?: string
  status?: string
  active?: boolean
  per_page?: number
}

// ─── API Functions ───────────────────────────────────────────────────────────

/**
 * Create a new POS order.
 */
export async function createOrder(
  data: CreateOrderRequest
): Promise<OrderData> {
  return apiPost<OrderData>('/pos/orders', data)
}

/**
 * Fetch orders with optional filters.
 */
export async function getOrders(
  params?: OrderListParams
): Promise<OrderListResponse> {
  const searchParams = new URLSearchParams()
  if (params?.terminal_id) searchParams.set('terminal_id', params.terminal_id)
  if (params?.shift_id) searchParams.set('shift_id', params.shift_id)
  if (params?.status) searchParams.set('status', params.status)
  if (params?.active !== undefined) searchParams.set('active', String(params.active))
  if (params?.per_page) searchParams.set('per_page', String(params.per_page))

  const queryString = searchParams.toString()
  const url = queryString ? `/pos/orders?${queryString}` : '/pos/orders'

  return apiGet<OrderListResponse>(url)
}

/**
 * Fetch a single order by ID.
 */
export async function getOrder(id: string): Promise<OrderData> {
  return apiGet<OrderData>(`/pos/orders/${id}`)
}

/**
 * Add a line item to an order.
 */
export async function addOrderLine(
  orderId: string,
  data: AddOrderLineRequest
): Promise<AddLineResponse> {
  return apiPost<AddLineResponse>(`/pos/orders/${orderId}/lines`, data)
}

/**
 * Modify a line item on an order.
 */
export async function modifyOrderLine(
  orderId: string,
  lineId: string,
  data: ModifyOrderLineRequest
): Promise<ModifyLineResponse> {
  return apiPatch<ModifyLineResponse>(
    `/pos/orders/${orderId}/lines/${lineId}`,
    data
  )
}

/**
 * Remove a line item from an order.
 */
export async function removeOrderLine(
  orderId: string,
  lineId: string
): Promise<OrderData> {
  return apiDelete<OrderData>(`/pos/orders/${orderId}/lines/${lineId}`)
}

/**
 * Send an order to the kitchen.
 */
export async function sendToKitchen(orderId: string): Promise<OrderData> {
  return apiPost<OrderData>(`/pos/orders/${orderId}/send-to-kitchen`)
}

/**
 * Close an order and convert to receipt.
 */
export async function closeOrder(orderId: string): Promise<OrderData> {
  return apiPost<OrderData>(`/pos/orders/${orderId}/close`)
}

/**
 * Cancel an order.
 */
export async function cancelOrder(
  orderId: string,
  reason?: string
): Promise<OrderData> {
  return apiPost<OrderData>(`/pos/orders/${orderId}/cancel`, { reason })
}
