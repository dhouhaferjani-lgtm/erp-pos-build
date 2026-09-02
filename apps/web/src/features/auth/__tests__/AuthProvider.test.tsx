import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, render, screen, waitFor } from '@testing-library/react'
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

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('does not request auth/me on mount when no token is stored', async () => {
    renderWithProviders(<AuthProvider><div>child</div></AuthProvider>)

    await waitFor(() => {
      expect(useAuthStore.getState().isLoading).toBe(false)
    })

    expect(mockApiGet).not.toHaveBeenCalled()
    expect(screen.getByText('child')).toBeInTheDocument()
  })

  it('hydrates the user when a stored token receives auth/me 200', async () => {
    useAuthStore.setState({
      user: {
        id: 'user-1', name: 'Persisted Name', email: 'old@test.com',
        tenant_id: 'tenant-1', roles: ['admin'], email_verified_at: null,
      },
      token: 'persisted-token',
      isAuthenticated: false,
      isLoading: true,
    })
    mockApiGet.mockResolvedValue({
      data: {
        data: {
          id: 'user-1', name: 'Test User', email: 'test@test.com',
          tenantId: 'tenant-1', roles: ['admin'], permissions: ['products.view'],
          emailVerifiedAt: '2026-01-01', impersonation: null,
        },
      },
    })

    renderWithProviders(<AuthProvider><div>child</div></AuthProvider>)

    await waitFor(() => {
      expect(useAuthStore.getState().user).toMatchObject({
        id: 'user-1',
        tenant_id: 'tenant-1',
        permissions: ['products.view'],
      })
    })

    expect(mockApiGet).toHaveBeenCalledTimes(1)
    expect(mockApiGet).toHaveBeenCalledWith('/auth/me')
    expect(useAuthStore.getState().isAuthenticated).toBe(true)
    expect(useAuthStore.getState().token).toBe('persisted-token')
  })

  it('logs out silently when a stored token receives auth/me 401', async () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined)
    const consoleWarn = vi.spyOn(console, 'warn').mockImplementation(() => undefined)
    useAuthStore.setState({
      user: {
        id: 'stale-user', name: 'Stale User', email: 'stale@test.com',
        tenant_id: 'tenant-1', roles: ['admin'], email_verified_at: null,
      },
      token: 'stale-token',
      isAuthenticated: true,
      isLoading: true,
    })
    mockApiGet.mockImplementation(async () => {
      // The real API interceptor owns token-identity-guarded 401 logout.
      useAuthStore.getState().logout()
      throw { response: { status: 401 } }
    })

    renderWithProviders(<AuthProvider><div>child</div></AuthProvider>)

    await waitFor(() => {
      const state = useAuthStore.getState()
      expect(state.token).toBeNull()
      expect(state.user).toBeNull()
      expect(state.isAuthenticated).toBe(false)
    })

    expect(mockApiGet).toHaveBeenCalledTimes(1)
    expect(mockClearAllAppState).not.toHaveBeenCalled()
    expect(consoleError).not.toHaveBeenCalled()
    expect(consoleWarn).not.toHaveBeenCalled()
    expect(screen.getByText('child')).toBeInTheDocument()
  })

  it('clears all app state when auth/me fails after previously succeeding', async () => {
    const queryClient = createTestQueryClient()
    useAuthStore.setState({
      user: null,
      token: 'persisted-token',
      isAuthenticated: false,
      isLoading: true,
    })
    mockApiGet.mockResolvedValueOnce({
      data: {
        data: {
          id: 'user-1', name: 'Test', email: 'test@test.com',
          tenantId: 'tenant-1', roles: ['admin'], permissions: [],
          emailVerifiedAt: null, impersonation: null,
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
    mockApiGet.mockImplementation(async () => {
      // Mirror the canonical interceptor before the query observes isError.
      useAuthStore.getState().logout()
      throw { response: { status: 401 } }
    })

    await act(async () => {
      await queryClient.resetQueries({ queryKey: ['auth', 'me'] })
    })

    await waitFor(() => {
      expect(mockClearAllAppState).toHaveBeenCalledWith(queryClient, { authAlreadyCleared: true })
    })
  })
})
