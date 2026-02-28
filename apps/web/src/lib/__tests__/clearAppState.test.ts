import { describe, it, expect, vi, beforeEach } from 'vitest'
import { QueryClient } from '@tanstack/react-query'
import { clearAllAppState } from '../clearAppState'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { useLocationStore } from '../../stores/locationStore'

describe('clearAllAppState', () => {
  let queryClient: QueryClient

  beforeEach(() => {
    queryClient = new QueryClient()
    vi.clearAllMocks()
    localStorage.clear()

    // Reset stores
    useAuthStore.getState().logout()
    useCompanyStore.getState().reset()
    useLocationStore.getState().reset()
  })

  it('clears React Query cache', () => {
    // Populate cache with some data
    queryClient.setQueryData(['products'], [{ id: 1, name: 'Widget' }])
    queryClient.setQueryData(['company-config'], { vertical: 'mechanic' })

    expect(queryClient.getQueryData(['products'])).toBeDefined()
    expect(queryClient.getQueryData(['company-config'])).toBeDefined()

    clearAllAppState(queryClient)

    expect(queryClient.getQueryData(['products'])).toBeUndefined()
    expect(queryClient.getQueryData(['company-config'])).toBeUndefined()
  })

  it('resets company store and clears localStorage', () => {
    // Set up company state
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

    expect(useCompanyStore.getState().currentCompanyId).toBe('company-1')
    expect(localStorage.getItem('autoerp-company-selection')).toBe('company-1')

    clearAllAppState(queryClient)

    expect(useCompanyStore.getState().currentCompanyId).toBeNull()
    expect(useCompanyStore.getState().companies).toEqual([])
    expect(localStorage.getItem('autoerp-company-selection')).toBeNull()
  })

  it('resets location store', () => {
    // Set up location state
    useLocationStore.setState({
      currentLocationId: 'loc-1',
      locations: [
        {
          id: 'loc-1',
          companyId: 'company-1',
          name: 'Main Shop',
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

    expect(useLocationStore.getState().currentLocationId).toBe('loc-1')

    clearAllAppState(queryClient)

    expect(useLocationStore.getState().currentLocationId).toBeNull()
    expect(useLocationStore.getState().locations).toEqual([])
  })

  it('clears auth store', () => {
    useAuthStore.getState().setAuth({
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.com',
      tenant_id: 'tenant-1',
      roles: ['admin'],
      email_verified_at: null,
    })

    expect(useAuthStore.getState().isAuthenticated).toBe(true)

    clearAllAppState(queryClient)

    expect(useAuthStore.getState().isAuthenticated).toBe(false)
    expect(useAuthStore.getState().user).toBeNull()
  })

  it('clears everything even if stores are already empty', () => {
    // Should not throw when stores are already in initial state
    expect(() => clearAllAppState(queryClient)).not.toThrow()

    expect(useAuthStore.getState().isAuthenticated).toBe(false)
    expect(useCompanyStore.getState().currentCompanyId).toBeNull()
    expect(useLocationStore.getState().currentLocationId).toBeNull()
  })
})
