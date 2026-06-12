import { api, apiGet, apiPost } from '@/lib/api'
import type {
  AggregateChannelOrdersParams,
  AggregateChannelOrdersResponse,
  ChannelListResponse,
  ChannelOrder,
  ChannelSyncOperation,
} from './types'

export function fetchChannels(): Promise<ChannelListResponse> {
  return apiGet<ChannelListResponse>('/channels')
}

export function createChannel(data: {
  company_id: string
  name: string
  adapter_type: string
  metadata?: Record<string, unknown>
}) {
  return apiPost('/channels', data)
}

export function testChannelConnection(channelId: string) {
  return apiPost(`/channels/${channelId}/test-connection`)
}

export function manualChannelResync(channelId: string) {
  return apiPost(`/channels/${channelId}/resync`)
}

export function fetchChannelOperations(channelId: string, status?: string): Promise<ChannelSyncOperation[]> {
  const query = status ? `?status=${encodeURIComponent(status)}` : ''
  return apiGet<ChannelSyncOperation[]>(`/channels/${channelId}/sync-operations${query}`)
}

export function fetchChannelOrders(channelId: string): Promise<ChannelOrder[]> {
  return apiGet<ChannelOrder[]>(`/channels/${channelId}/orders`)
}

export function promoteChannelOrder(channelId: string, orderId: string) {
  return apiPost(`/channels/${channelId}/orders/${orderId}/promote`)
}

/**
 * Aggregate channel orders across ALL of the company's channels.
 *
 * PAGINATED endpoint ({ data, meta }): uses `api.get` directly and
 * returns `response.data` — do NOT switch to `apiGet`, which unwraps
 * `data.data` and silently drops the pagination `meta`.
 */
export async function fetchAggregateChannelOrders(
  params: AggregateChannelOrdersParams = {},
): Promise<AggregateChannelOrdersResponse> {
  const search = new URLSearchParams()
  if (params.status) search.set('status', params.status)
  if (params.channel_id) search.set('channel_id', params.channel_id)
  if (params.page) search.set('page', String(params.page))
  if (params.per_page) search.set('per_page', String(params.per_page))

  const query = search.toString()
  const response = await api.get<AggregateChannelOrdersResponse>(
    `/channels/orders${query ? `?${query}` : ''}`,
  )

  return response.data
}
