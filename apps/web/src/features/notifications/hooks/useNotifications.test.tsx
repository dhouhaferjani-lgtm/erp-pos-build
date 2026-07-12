import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, cleanup, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import {
  useMarkAllNotificationsRead,
  useMarkNotificationRead,
  useNotificationsList,
  useUnreadNotificationCount,
} from './useNotifications'

const mockGetNotifications = vi.hoisted(() => vi.fn())
const mockGetUnreadNotificationCount = vi.hoisted(() => vi.fn())
const mockMarkAllNotificationsRead = vi.hoisted(() => vi.fn())
const mockMarkNotificationRead = vi.hoisted(() => vi.fn())

vi.mock('../api/notificationsApi', () => ({
  getNotifications: mockGetNotifications,
  getUnreadNotificationCount: mockGetUnreadNotificationCount,
  markAllNotificationsRead: mockMarkAllNotificationsRead,
  markNotificationRead: mockMarkNotificationRead,
}))

function setScope(userId: string | null, tenantId: string | null, companyId: string | null = 'company-1') {
  useAuthStore.setState({
    user: userId && tenantId
      ? { id: userId, name: 'User', email: 'user@example.test', tenant_id: tenantId, roles: [], email_verified_at: null }
      : null,
    token: userId ? 'token' : null,
    isAuthenticated: userId !== null,
    isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: companyId, companies: [], isLoading: false })
}

function createClient() {
  return new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false } },
  })
}

function wrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  setScope('user-1', 'tenant-1')
  mockGetUnreadNotificationCount.mockResolvedValue({ count: 2 })
  mockGetNotifications.mockResolvedValue({
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 },
  })
  mockMarkNotificationRead.mockResolvedValue(undefined)
  mockMarkAllNotificationsRead.mockResolvedValue(undefined)
})

afterEach(() => {
  cleanup()
  setScope(null, null, null)
})

describe('notification hooks', () => {
  it('scopes the unread-count key by user and tenant and polls every minute', async () => {
    const queryClient = createClient()
    const { result } = renderHook(() => useUnreadNotificationCount(), { wrapper: wrapper(queryClient) })

    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })

    const query = queryClient.getQueryCache().find({
      queryKey: ['notifications', 'user-1', 'unread-count', 'tenant-1', 'company-1'],
    })
    expect(query).toBeDefined()
    expect((query?.options as { refetchInterval?: unknown }).refetchInterval).toBe(60_000)
  })

  it('does not fetch without both a user and tenant', () => {
    setScope(null, null)
    const anonymousClient = createClient()
    const anonymous = renderHook(() => useUnreadNotificationCount(), { wrapper: wrapper(anonymousClient) })
    anonymous.unmount()

    setScope('user-1', 'tenant-1')
    useAuthStore.setState((state) => ({
      user: state.user ? { ...state.user, tenant_id: '' } : null,
    }))
    const tenantlessClient = createClient()
    renderHook(() => useNotificationsList(true), { wrapper: wrapper(tenantlessClient) })

    expect(mockGetUnreadNotificationCount).not.toHaveBeenCalled()
    expect(mockGetNotifications).not.toHaveBeenCalled()
  })

  it('fetches the list only when the panel is enabled', async () => {
    const queryClient = createClient()
    const { rerender } = renderHook(
      ({ enabled }) => useNotificationsList(enabled),
      { initialProps: { enabled: false }, wrapper: wrapper(queryClient) },
    )

    expect(mockGetNotifications).not.toHaveBeenCalled()
    rerender({ enabled: true })

    await waitFor(() => { expect(mockGetNotifications).toHaveBeenCalledTimes(1) })
    expect(queryClient.getQueryData([
      'notifications', 'user-1', 'list', 'tenant-1', 'company-1',
    ])).toBeDefined()
  })

  it('invalidates the raw user prefix after marking one notification read', async () => {
    const queryClient = createClient()
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries')
    const { result } = renderHook(() => useMarkNotificationRead(), { wrapper: wrapper(queryClient) })

    await act(async () => { await result.current.mutateAsync('notification-1') })

    expect(mockMarkNotificationRead).toHaveBeenCalledWith('notification-1')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['notifications', 'user-1'] })
  })

  it('invalidates the raw user prefix after marking all notifications read', async () => {
    const queryClient = createClient()
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries')
    const { result } = renderHook(() => useMarkAllNotificationsRead(), { wrapper: wrapper(queryClient) })

    await act(async () => { await result.current.mutateAsync() })

    expect(mockMarkAllNotificationsRead).toHaveBeenCalledOnce()
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['notifications', 'user-1'] })
  })
})
