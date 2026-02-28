import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { renderHook, act } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { type ReactNode } from 'react'
import { useLogout } from '../useLogout'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import { useLocationStore } from '../../../stores/locationStore'

// Mock the api module
vi.mock('../../../lib/api', () => ({
  api: {
    post: vi.fn().mockResolvedValue({}),
  },
}))

// Mock window.location
const originalLocation = window.location
beforeEach(() => {
  Object.defineProperty(window, 'location', {
    writable: true,
    value: { href: '/' },
  })
})
afterEach(() => {
  Object.defineProperty(window, 'location', {
    writable: true,
    value: originalLocation,
  })
})

describe('useLogout', () => {
  let queryClient: QueryClient

  beforeEach(() => {
    queryClient = new QueryClient()
    vi.clearAllMocks()
    localStorage.clear()

    // Set up a logged-in state
    useAuthStore.getState().setAuth({
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.com',
      tenant_id: 'tenant-1',
      roles: ['admin'],
      email_verified_at: null,
    })

    useCompanyStore.getState().setCompanies([
      {
        id: 'company-1',
        name: 'Test Co',
        legalName: 'Test Co SARL',
        taxId: null,
        countryCode: 'FR',
        currency: 'EUR',
        locale: 'fr',
        timezone: 'Europe/Paris',
      },
    ])
    useCompanyStore.getState().setCurrentCompany('company-1')

    useLocationStore.setState({
      currentLocationId: 'loc-1',
      locations: [
        {
          id: 'loc-1',
          companyId: 'company-1',
          name: 'Main',
          code: 'MAIN',
          type: 'shop',
          phone: null,
          email: null,
          addressStreet: null,
          addressCity: null,
          addressPostalCode: null,
          addressCountry: null,
          isDefault: true,
          isActive: true,
          posEnabled: true,
          createdAt: '2024-01-01',
          updatedAt: '2024-01-01',
        },
      ],
      isLoading: false,
    })

    // Populate some cached queries
    queryClient.setQueryData(['products'], [{ id: 1 }])
    queryClient.setQueryData(['company-config', 'company-1'], { vertical: 'mechanic' })
  })

  afterEach(() => {
    localStorage.clear()
    useAuthStore.getState().logout()
    useCompanyStore.getState().reset()
    useLocationStore.getState().reset()
  })

  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  )

  it('REGRESSION: clears React Query cache on logout', async () => {
    expect(queryClient.getQueryData(['products'])).toBeDefined()

    const { result } = renderHook(() => useLogout(), { wrapper })

    await act(async () => {
      await result.current()
    })

    expect(queryClient.getQueryData(['products'])).toBeUndefined()
    expect(queryClient.getQueryData(['company-config', 'company-1'])).toBeUndefined()
  })

  it('REGRESSION: resets company store on logout (no stale company ID in localStorage)', async () => {
    expect(localStorage.getItem('autoerp-company-selection')).toBe('company-1')
    expect(useCompanyStore.getState().currentCompanyId).toBe('company-1')

    const { result } = renderHook(() => useLogout(), { wrapper })

    await act(async () => {
      await result.current()
    })

    expect(useCompanyStore.getState().currentCompanyId).toBeNull()
    expect(useCompanyStore.getState().companies).toEqual([])
    expect(localStorage.getItem('autoerp-company-selection')).toBeNull()
  })

  it('REGRESSION: resets location store on logout', async () => {
    expect(useLocationStore.getState().currentLocationId).toBe('loc-1')

    const { result } = renderHook(() => useLogout(), { wrapper })

    await act(async () => {
      await result.current()
    })

    expect(useLocationStore.getState().currentLocationId).toBeNull()
    expect(useLocationStore.getState().locations).toEqual([])
  })

  it('REGRESSION: clears auth store on logout', async () => {
    expect(useAuthStore.getState().isAuthenticated).toBe(true)

    const { result } = renderHook(() => useLogout(), { wrapper })

    await act(async () => {
      await result.current()
    })

    expect(useAuthStore.getState().isAuthenticated).toBe(false)
    expect(useAuthStore.getState().user).toBeNull()
  })

  it('REGRESSION: clears all state even if API logout fails', async () => {
    const { api } = await import('../../../lib/api')
    vi.mocked(api.post).mockRejectedValueOnce(new Error('Network error'))

    const { result } = renderHook(() => useLogout(), { wrapper })

    await act(async () => {
      await result.current()
    })

    // All state should still be cleared despite API failure
    expect(useAuthStore.getState().isAuthenticated).toBe(false)
    expect(useCompanyStore.getState().currentCompanyId).toBeNull()
    expect(useLocationStore.getState().currentLocationId).toBeNull()
    expect(queryClient.getQueryData(['products'])).toBeUndefined()
  })

  it('redirects to /login after logout', async () => {
    const { result } = renderHook(() => useLogout(), { wrapper })

    await act(async () => {
      await result.current()
    })

    expect(window.location.href).toBe('/login')
  })
})
