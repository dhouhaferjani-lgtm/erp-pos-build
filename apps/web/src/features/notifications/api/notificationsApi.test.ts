import { beforeEach, describe, expect, it, vi } from 'vitest'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', () => ({
  api: { get: mockGet },
  apiGet: mockApiGet,
  apiPost: mockApiPost,
}))

import {
  getNotifications,
  getUnreadNotificationCount,
  markAllNotificationsRead,
  markNotificationRead,
} from './notificationsApi'

describe('notificationsApi', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('preserves list metadata by using the raw api client', async () => {
    const payload = {
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 },
    }
    mockGet.mockResolvedValue({ data: payload })

    await expect(getNotifications()).resolves.toEqual(payload)
    expect(mockGet).toHaveBeenCalledWith('/notifications', { params: { per_page: 15 } })
    expect(mockApiGet).not.toHaveBeenCalled()
  })

  it('uses the unwrapping helper for unread count', async () => {
    mockApiGet.mockResolvedValue({ count: 4 })

    await expect(getUnreadNotificationCount()).resolves.toEqual({ count: 4 })
    expect(mockApiGet).toHaveBeenCalledWith('/notifications/unread-count')
  })

  it('posts read mutations to their ownership-scoped endpoints', async () => {
    mockApiPost.mockResolvedValue(undefined)

    await markNotificationRead('notification-1')
    await markAllNotificationsRead()

    expect(mockApiPost).toHaveBeenNthCalledWith(1, '/notifications/notification-1/read')
    expect(mockApiPost).toHaveBeenNthCalledWith(2, '/notifications/read-all')
  })
})
