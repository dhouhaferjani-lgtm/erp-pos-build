import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { CompanyConfigProvider, useCompanyConfig } from '../CompanyConfigContext'
import * as api from '../../lib/api'
import { resetAuth, seedAuth } from '../../test/seedAuth'
import {
  defaultCompanyConfig,
  pharmacyCompanyConfig,
} from '../../test/fixtures/companyConfig'

// Mock the API
vi.mock('../../lib/api', () => ({
  apiGet: vi.fn(),
}))

/**
 * Strategy for testing CompanyConfigProvider
 * -------------------------------------------
 * Unlike feature-level tests (which use `renderWithProviders` and seed the
 * QueryClient cache to bypass fetching), this file exercises the provider
 * itself — so it must go through the real fetch path.
 *
 * The provider gates its query on `isAuthenticated` (see
 * `CompanyConfigContext.tsx`: `enabled: isAuthenticated`). If we leave the
 * auth store in its default unauthenticated state, the query never fires,
 * `apiGet` is never called, and the mocked response sits unused. That would
 * defeat the point of tests like "handles API errors gracefully" and
 * "caches config data with 1 hour staleTime" which explicitly assert on
 * fetch behaviour.
 *
 * We therefore seed the auth store to an authenticated state in `beforeEach`
 * and reset it in `afterEach`. Fixtures come from `@/test/fixtures/companyConfig`
 * (Task 1.1), so the mocked response shape always matches the real
 * `CompanyConfig` contract including fields like `currency`, `locale`,
 * `smart_prompts_enabled`, etc.
 */

const testUser = {
  id: 'test-user-id',
  name: 'Test User',
  email: 'test@example.com',
  tenant_id: 'test-tenant',
  roles: ['admin'],
  email_verified_at: null,
}

describe('CompanyConfigContext', () => {
  let queryClient: QueryClient

  beforeEach(() => {
    // Create a new QueryClient for each test to ensure isolation
    queryClient = new QueryClient({
      defaultOptions: {
        queries: {
          retry: false, // Disable retries in tests
        },
      },
    })
    vi.clearAllMocks()

    // Seed the auth + company stores so the provider's query is enabled.
    // CompanyConfigProvider gates on isAuthenticated AND a non-null
    // tenant_id AND a non-null currentCompanyId; without seeding the
    // company store the query stays disabled and apiGet is never called.
    seedAuth({
      userId: testUser.id,
      email: testUser.email,
      tenantId: testUser.tenant_id,
      roles: testUser.roles,
    })
  })

  afterEach(() => {
    resetAuth()
    queryClient.clear()
  })

  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <CompanyConfigProvider>{children}</CompanyConfigProvider>
    </QueryClientProvider>
  )

  it('provides company config data when loaded', async () => {
    vi.mocked(api.apiGet).mockResolvedValueOnce(defaultCompanyConfig)

    const { result } = renderHook(() => useCompanyConfig(), { wrapper })

    // Initially loading
    expect(result.current.isLoading).toBe(true)
    expect(result.current.config).toBeNull()

    // Wait for data to load
    await waitFor(() => {
      expect(result.current.isLoading).toBe(false)
    })

    // Check data is available
    expect(result.current.config).toEqual(defaultCompanyConfig)
    expect(result.current.error).toBeNull()
  })

  it('provides vertical from config', async () => {
    vi.mocked(api.apiGet).mockResolvedValueOnce(pharmacyCompanyConfig)

    const { result } = renderHook(() => useCompanyConfig(), { wrapper })

    await waitFor(() => {
      expect(result.current.config?.vertical).toBe('pharmacy')
    })
  })

  it('provides all_enabled_modules from config', async () => {
    const restaurantConfig = {
      ...defaultCompanyConfig,
      vertical: 'restaurant',
      default_modules: ['Identity', 'Menu', 'Tables'],
      enabled_extras: ['Appointments'],
      all_enabled_modules: ['Identity', 'Menu', 'Tables', 'Appointments'],
    }
    vi.mocked(api.apiGet).mockResolvedValueOnce(restaurantConfig)

    const { result } = renderHook(() => useCompanyConfig(), { wrapper })

    await waitFor(() => {
      expect(result.current.config?.all_enabled_modules).toEqual([
        'Identity',
        'Menu',
        'Tables',
        'Appointments',
      ])
    })
  })

  it('provides hasModule utility function', async () => {
    const mechanicConfig = {
      ...defaultCompanyConfig,
      vertical: 'mechanic',
      default_modules: ['Identity', 'Vehicle', 'Workshop'],
      enabled_extras: ['Fleet'],
      all_enabled_modules: ['Identity', 'Vehicle', 'Workshop', 'Fleet'],
    }
    vi.mocked(api.apiGet).mockResolvedValueOnce(mechanicConfig)

    const { result } = renderHook(() => useCompanyConfig(), { wrapper })

    await waitFor(() => {
      expect(result.current.hasModule('Vehicle')).toBe(true)
      expect(result.current.hasModule('Fleet')).toBe(true)
      expect(result.current.hasModule('BatchExpiry')).toBe(false)
    })
  })

  it('handles API errors gracefully', async () => {
    const mockError = new Error('Failed to fetch config')
    vi.mocked(api.apiGet).mockRejectedValue(mockError) // Reject all attempts

    const { result } = renderHook(() => useCompanyConfig(), { wrapper })

    // Initially loading
    expect(result.current.isLoading).toBe(true)

    // Wait for error state (with retry: 1, it will try twice)
    await waitFor(
      () => {
        expect(result.current.error).toBeTruthy()
      },
      { timeout: 3000 }
    )

    // Check final error state
    expect(result.current.isLoading).toBe(false)
    expect(result.current.config).toBeNull()
  })

  it('caches config data with 1 hour staleTime', async () => {
    vi.mocked(api.apiGet).mockResolvedValueOnce(defaultCompanyConfig)

    const { result, rerender } = renderHook(() => useCompanyConfig(), { wrapper })

    // Wait for initial load
    await waitFor(() => {
      expect(result.current.isLoading).toBe(false)
    })

    // API should have been called once
    expect(api.apiGet).toHaveBeenCalledTimes(1)
    expect(api.apiGet).toHaveBeenCalledWith('/company/config')

    // Rerender hook (simulates remount)
    rerender()

    // API should NOT be called again (cached)
    expect(api.apiGet).toHaveBeenCalledTimes(1)

    // Data should still be available
    expect(result.current.config).toEqual(defaultCompanyConfig)
  })

  it('hasModule returns false when config is not loaded', () => {
    vi.mocked(api.apiGet).mockImplementation(
      () => new Promise(() => {}) // Never resolves
    )

    const { result } = renderHook(() => useCompanyConfig(), { wrapper })

    // While loading, hasModule should return false
    expect(result.current.hasModule('Vehicle')).toBe(false)
  })

  it('handles empty enabled_extras array', async () => {
    const retailConfig = {
      ...defaultCompanyConfig,
      vertical: 'retail',
      default_modules: ['Identity', 'Catalog'],
      enabled_extras: [],
      all_enabled_modules: ['Identity', 'Catalog'],
    }
    vi.mocked(api.apiGet).mockResolvedValueOnce(retailConfig)

    const { result } = renderHook(() => useCompanyConfig(), { wrapper })

    await waitFor(() => {
      expect(result.current.config?.enabled_extras).toEqual([])
      expect(result.current.config?.all_enabled_modules).toEqual(['Identity', 'Catalog'])
    })
  })

  it('handles multiple enabled extras', async () => {
    const mechanicConfig = {
      ...defaultCompanyConfig,
      vertical: 'mechanic',
      default_modules: ['Identity', 'Vehicle', 'Workshop'],
      enabled_extras: ['Fleet', 'Appointments', 'Recipe'],
      all_enabled_modules: [
        'Identity',
        'Vehicle',
        'Workshop',
        'Fleet',
        'Appointments',
        'Recipe',
      ],
    }
    vi.mocked(api.apiGet).mockResolvedValueOnce(mechanicConfig)

    const { result } = renderHook(() => useCompanyConfig(), { wrapper })

    await waitFor(() => {
      expect(result.current.config?.enabled_extras).toHaveLength(3)
      expect(result.current.config?.all_enabled_modules).toHaveLength(6)
    })
  })

  it('throws error when used outside provider', () => {
    // Wrapper with only QueryClientProvider, not CompanyConfigProvider
    const bareWrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    )

    expect(() => {
      renderHook(() => useCompanyConfig(), { wrapper: bareWrapper })
    }).toThrow('useCompanyConfig must be used within a CompanyConfigProvider')
  })
})
