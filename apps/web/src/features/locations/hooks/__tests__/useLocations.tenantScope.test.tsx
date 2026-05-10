import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient } from '@/test/renderWithProviders'

import { locationKeys, useLocation, useLocations } from '../useLocations'
import type { Location } from '../../types'

const mockGetLocations = vi.hoisted(() => vi.fn())
const mockGetLocation = vi.hoisted(() => vi.fn())

vi.mock('../../api/locations', () => ({
  getLocations: mockGetLocations,
  getLocation: mockGetLocation,
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test',
      email: 't@example.test',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: companyId,
    companies: [],
    isLoading: false,
  })
}

function makeWrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

function persistentQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  })
}

function locationFixture(id: string): Location {
  return {
    id,
    companyId: 'company-1',
    name: `Location ${id}`,
    code: id.toUpperCase(),
    type: 'warehouse',
    phone: null,
    email: null,
    addressStreet: null,
    addressCity: null,
    addressPostalCode: null,
    addressCountry: null,
    isDefault: false,
    isActive: true,
    posEnabled: true,
    createdAt: '2026-01-01T00:00:00Z',
    updatedAt: '2026-01-01T00:00:00Z',
  }
}

beforeEach(() => {
  mockGetLocations.mockReset()
  mockGetLocations.mockResolvedValue([])
  mockGetLocation.mockReset()
  mockGetLocation.mockResolvedValue(locationFixture('loc-1'))
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

describe('location hook queryKey shapes', () => {
  it('wraps list and detail keys with tenant/company (.336, .337)', async () => {
    setTenant('tenant-A', 'company-1')
    const client = createTestQueryClient()
    const wrapper = makeWrapper(client)

    const { result: list } = renderHook(() => useLocations(), { wrapper })
    const { result: detail } = renderHook(() => useLocation('loc-1'), { wrapper })

    await waitFor(() => {
      expect(list.current.isSuccess).toBe(true)
      expect(detail.current.isSuccess).toBe(true)
    })

    const keys = client.getQueryCache().getAll().map((q) => q.queryKey as unknown[])
    expect(keys.find((k) => k[0] === 'locations' && k[1] === 'list')).toEqual([
      'locations',
      'list',
      'tenant-A',
      'company-1',
    ])
    expect(keys.find((k) => k[0] === 'locations' && k[1] === 'detail')).toEqual([
      'locations',
      'detail',
      'loc-1',
      'tenant-A',
      'company-1',
    ])
  })

  it('queryKeys differ across tenants (useLocations)', async () => {
    setTenant('tenant-A', 'company-1')
    const clientA = createTestQueryClient()
    const { result: resultA } = renderHook(() => useLocations(), {
      wrapper: makeWrapper(clientA),
    })
    await waitFor(() => { expect(resultA.current.isSuccess).toBe(true) })
    const keysA = JSON.stringify(clientA.getQueryCache().getAll().map((q) => q.queryKey))

    setTenant('tenant-B', 'company-1')
    const clientB = createTestQueryClient()
    const { result: resultB } = renderHook(() => useLocations(), {
      wrapper: makeWrapper(clientB),
    })
    await waitFor(() => { expect(resultB.current.isSuccess).toBe(true) })

    const keysB = JSON.stringify(clientB.getQueryCache().getAll().map((q) => q.queryKey))
    expect(keysA).toContain('tenant-A')
    expect(keysB).toContain('tenant-B')
    expect(keysA).not.toEqual(keysB)
  })

  it('does not fetch without tenant/company scope', () => {
    const client = createTestQueryClient()
    const { result } = renderHook(() => useLocations(), { wrapper: makeWrapper(client) })

    expect(result.current.fetchStatus).toBe('idle')
    expect(mockGetLocations).not.toHaveBeenCalled()
  })
})

describe('cross-tenant location isolation', () => {
  it('tenant-A location list data does not contain tenant-B entries (L18)', async () => {
    const client = persistentQueryClient()
    const tenantBKey = ['locations', 'list', 'tenant-B', 'company-1']
    client.setQueryData(tenantBKey, [locationFixture('leaked-tenant-b-location')])

    setTenant('tenant-A', 'company-1')
    const { result } = renderHook(() => useLocations(), { wrapper: makeWrapper(client) })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })

    const tenantAKey = ['locations', 'list', 'tenant-A', 'company-1']
    const tenantAData = client.getQueryData<Location[]>(tenantAKey)
    expect(tenantAData).toEqual([])
    expect(tenantAData?.map((location) => location.id)).not.toContain(
      'leaked-tenant-b-location',
    )
    expect(client.getQueryData(tenantBKey)).toEqual([
      locationFixture('leaked-tenant-b-location'),
    ])
  })
})

describe('locationKeys factory shape', () => {
  it('exposes stable unscoped base keys', () => {
    expect(locationKeys.all).toEqual(['locations'])
    expect(locationKeys.lists()).toEqual(['locations', 'list'])
    expect(locationKeys.list()).toEqual(['locations', 'list'])
    expect(locationKeys.details()).toEqual(['locations', 'detail'])
    expect(locationKeys.detail('loc-1')).toEqual(['locations', 'detail', 'loc-1'])
  })
})
