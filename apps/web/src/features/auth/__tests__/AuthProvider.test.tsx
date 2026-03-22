import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { useAuthStore } from '../../../stores/authStore'

const { mockClearAllAppState } = vi.hoisted(() => ({
  mockClearAllAppState: vi.fn(),
}))

vi.mock('../../../lib/clearAppState', () => ({
  clearAllAppState: mockClearAllAppState,
}))

const { mockApiGet } = vi.hoisted(() => ({
  mockApiGet: vi.fn(),
}))

vi.mock('../../../lib/api', () => ({
  api: { get: mockApiGet },
}))

import { AuthProvider } from '../AuthProvider'

function createTestQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false },
    },
  })
}

function renderWithProviders(ui: React.ReactElement, queryClient?: QueryClient) {
  const qc = queryClient ?? createTestQueryClient()
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>
  )
}

describe('AuthProvider', () => {
  beforeEach(() => {
    useAuthStore.getState().logout()
    vi.clearAllMocks()
  })

  it('does NOT call clearAllAppState on initial 401 (no prior auth)', async () => {
    mockApiGet.mockRejectedValue({ response: { status: 401 } })
    renderWithProviders(<AuthProvider><div>child</div></AuthProvider>)
    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledWith('/auth/me')
    })
    await waitFor(() => {
      expect(mockClearAllAppState).not.toHaveBeenCalled()
    })
  })

  it('sets isLoading to false on initial 401 (prevents infinite spinner)', async () => {
    mockApiGet.mockRejectedValue({ response: { status: 401 } })
    renderWithProviders(<AuthProvider><div>child</div></AuthProvider>)
    await waitFor(() => {
      expect(useAuthStore.getState().isLoading).toBe(false)
    })
  })

  it('DOES call clearAllAppState when auth/me fails after previously succeeding', async () => {
    const queryClient = createTestQueryClient()
    mockApiGet.mockResolvedValueOnce({
      data: {
        data: {
          id: 'user-1', name: 'Test', email: 'test@test.com',
          tenantId: 'tenant-1', roles: ['admin'], emailVerifiedAt: null,
        },
      },
    })
    renderWithProviders(
      <AuthProvider><div>child</div></AuthProvider>,
      queryClient,
    )
    await waitFor(() => {
      expect(useAuthStore.getState().user).not.toBeNull()
    })
    mockApiGet.mockRejectedValue({ response: { status: 401 } })
    // resetQueries clears cached data AND refetches, so isError becomes true
    void queryClient.resetQueries({ queryKey: ['auth', 'me'] })
    await waitFor(() => {
      expect(mockClearAllAppState).toHaveBeenCalledWith(queryClient)
    })
  })

  it('updates user state when auth/me succeeds', async () => {
    mockApiGet.mockResolvedValue({
      data: {
        data: {
          id: 'user-1', name: 'Test User', email: 'test@test.com',
          tenantId: 'tenant-1', roles: ['admin'], emailVerifiedAt: '2026-01-01',
        },
      },
    })
    renderWithProviders(<AuthProvider><div>child</div></AuthProvider>)
    await waitFor(() => {
      const state = useAuthStore.getState()
      expect(state.user?.id).toBe('user-1')
      expect(state.user?.tenant_id).toBe('tenant-1')
      expect(state.isLoading).toBe(false)
    })
  })

  it('does NOT trigger clearAllAppState on transient refetch failure after setAuth', async () => {
    mockApiGet.mockRejectedValue({ response: { status: 401 } })
    const queryClient = createTestQueryClient()
    renderWithProviders(
      <AuthProvider><div>child</div></AuthProvider>,
      queryClient,
    )
    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalled()
    })
    useAuthStore.getState().setAuth({
      id: 'user-1', name: 'Test', email: 'test@test.com',
      tenant_id: 'tenant-1', roles: ['admin'], email_verified_at: null,
    }, 'fake-token')
    void queryClient.invalidateQueries({ queryKey: ['auth', 'me'] })
    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledTimes(2)
    })
    expect(mockClearAllAppState).not.toHaveBeenCalled()
  })
})
