import { api, apiGet, apiPost } from '@/lib/api'
import type { OffsetPaginationMeta } from '@/types/pagination'

export interface AppNotification {
  id: string
  type: string
  data: Record<string, unknown>
  read_at: string | null
  created_at: string
}

export interface NotificationsResponse {
  data: AppNotification[]
  meta: OffsetPaginationMeta
}

export interface UnreadNotificationCount {
  count: number
}

export async function getNotifications(): Promise<NotificationsResponse> {
  const response = await api.get<NotificationsResponse>('/notifications', {
    params: { per_page: 15 },
  })
  return response.data
}

export function getUnreadNotificationCount(): Promise<UnreadNotificationCount> {
  return apiGet<UnreadNotificationCount>('/notifications/unread-count')
}

export async function markNotificationRead(id: string): Promise<void> {
  await apiPost<unknown>(`/notifications/${id}/read`)
}

export async function markAllNotificationsRead(): Promise<void> {
  await apiPost<unknown>('/notifications/read-all')
}
