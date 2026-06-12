export type ChannelConnectionStatus = 'pending' | 'connected' | 'failed' | 'suspended'
export type ChannelOrderStatus = 'pending' | 'processed' | 'failed' | 'ignored'
export type SyncOperationStatus = 'pending' | 'acknowledged' | 'failed' | 'retried'

export interface Channel {
  id: string
  company_id: string
  name: string
  adapter_type: string
  is_active: boolean
  connection_status: ChannelConnectionStatus
  last_successful_sync_at: string | null
  last_error: string | null
  metadata: Record<string, unknown> | null
}

export interface ChannelListResponse {
  channels: Channel[]
  registered_adapters: string[]
}

export interface ChannelProductMapping {
  id: string
  channel_id: string
  product_id: string
  variant_id: string | null
  is_published: boolean
  external_id: string | null
  last_synced_at: string | null
  price_override: string | null
  quantity_cap: number | null
}

export interface ChannelOrder {
  id: string
  channel_id: string
  external_order_id: string
  received_at: string
  processed_at: string | null
  status: ChannelOrderStatus
  error_message: string | null
}

/**
 * Subset of the eager-loaded `channel` relation the aggregate
 * orders page consumes.
 */
export interface AggregateChannelOrderChannel {
  id: string
  name: string
}

/**
 * Row shape of GET /channels/orders (aggregate endpoint): the raw
 * ChannelOrder model fields plus the eager-loaded channel relation.
 * `payload` is the adapter-specific external order payload — untyped
 * by design; consumers must type-guard any access.
 */
export interface AggregateChannelOrderRow extends ChannelOrder {
  payload: Record<string, unknown> | null
  document_id: string | null
  channel: AggregateChannelOrderChannel
}

export interface AggregateChannelOrdersMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface AggregateChannelOrdersResponse {
  data: AggregateChannelOrderRow[]
  meta: AggregateChannelOrdersMeta
}

export interface AggregateChannelOrdersParams {
  status?: ChannelOrderStatus | undefined
  channel_id?: string | undefined
  page?: number | undefined
  per_page?: number | undefined
}

export interface ChannelSyncOperation {
  id: string
  channel_id: string
  operation_type: string
  idempotency_key: string
  dispatched_at: string | null
  acknowledged_at: string | null
  status: SyncOperationStatus
  attempt_count: number
  next_retry_at: string | null
}
