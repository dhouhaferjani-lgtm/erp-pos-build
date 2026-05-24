import { apiGet, apiPost } from '@/lib/api'
import type { ChannelListResponse, ChannelOrder, ChannelSyncOperation } from './types'

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
