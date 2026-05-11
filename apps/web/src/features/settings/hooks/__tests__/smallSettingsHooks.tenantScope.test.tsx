import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import type { Country, CountryFilters } from '../../types/country'
import { POS_REFUND_POLICY_DEFAULTS } from '../../types/posRefundPolicies'
import type { SubscriptionInfo } from '../../types/subscription'
import { useCountries, useCountry } from '../useCountries'
import { usePosRefundPolicies } from '../usePosRefundPolicies'
import { useSubscription } from '../useSubscription'
import { useUpdatePosRefundPolicies } from '../useUpdatePosRefundPolicies'

const mockGetCountries = vi.hoisted(() => vi.fn())
const mockGetCountry = vi.hoisted(() => vi.fn())
const mockGetPosRefundPolicies = vi.hoisted(() => vi.fn())
const mockUpdatePosRefundPolicies = vi.hoisted(() => vi.fn())
const mockGetSubscription = vi.hoisted(() => vi.fn())

vi.mock('../../api/country', () => ({
  getCountries: mockGetCountries,
  getCountry: mockGetCountry,
}))

vi.mock('../../api/posRefundPoliciesApi', () => ({
  getPosRefundPolicies: mockGetPosRefundPolicies,
  updatePosRefundPolicies: mockUpdatePosRefundPolicies,
}))

vi.mock('../../api/subscription', () => ({
  getSubscription: mockGetSubscription,
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.test',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'test-token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: companyId,
    companies: [{
      id: companyId,
      name: 'Test Company',
      legalName: 'Test Company LLC',
      taxId: null,
      countryCode: 'TN',
      currency: 'TND',
      locale: 'en_US',
      timezone: 'Africa/Tunis',
    }],
    isLoading: false,
  })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function createPersistentQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  })
}

function makeWrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

function cacheKeys(queryClient: QueryClient): unknown[][] {
  return queryClient
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
}

const countryFixture: Country = {
  code: 'TN',
  name: 'Tunisia',
  native_name: null,
  currency_code: 'TND',
  currency_symbol: 'DT',
  phone_prefix: '+216',
  date_format: 'dd/MM/yyyy',
  default_locale: 'fr_TN',
  default_timezone: 'Africa/Tunis',
  is_active: true,
  tax_id_label: null,
  tax_id_regex: null,
  created_at: '2026-05-11T09:00:00Z',
}

const subscriptionFixture: SubscriptionInfo = {
  subscription: null,
  usage: {
    companies: 1,
    locations: 1,
    users: 1,
  },
  limits: {
    max_companies: 1,
    max_locations: 1,
    max_users: 5,
  },
  trial_days_remaining: 0,
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockGetCountries.mockResolvedValue([countryFixture])
  mockGetCountry.mockResolvedValue(countryFixture)
  mockGetPosRefundPolicies.mockResolvedValue(POS_REFUND_POLICY_DEFAULTS)
  mockUpdatePosRefundPolicies.mockResolvedValue(POS_REFUND_POLICY_DEFAULTS)
  mockGetSubscription.mockResolvedValue(subscriptionFixture)
})

afterEach(() => {
  resetTenant()
})

describe('small settings hooks tenant scope', () => {
  it('wraps small settings read query keys with the active tenant and company (.656-.659)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)
    const filters: CountryFilters = { is_active: true }

    const { result } = renderHook(() => ({
      countries: useCountries(filters),
      country: useCountry('TN'),
      refundPolicies: usePosRefundPolicies(),
      subscription: useSubscription(),
    }), { wrapper })

    await waitFor(() => {
      expect(result.current.countries.isSuccess).toBe(true)
      expect(result.current.country.isSuccess).toBe(true)
      expect(result.current.refundPolicies.isSuccess).toBe(true)
      expect(result.current.subscription.isSuccess).toBe(true)
    })

    expect(cacheKeys(queryClient)).toEqual(expect.arrayContaining([
      ['countries', filters, 'tenant-A', 'company-1'],
      ['country', 'TN', 'tenant-A', 'company-1'],
      ['pos-refund-policies', 'company-1', 'tenant-A', 'company-1'],
      ['subscription', 'tenant-A', 'company-1'],
    ]))
  })

  it('does not fetch small settings reads without tenant/company scope', () => {
    resetTenant()
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)

    renderHook(() => ({
      countries: useCountries(),
      country: useCountry('TN'),
      refundPolicies: usePosRefundPolicies(),
      subscription: useSubscription(),
    }), { wrapper })

    expect(mockGetCountries).not.toHaveBeenCalled()
    expect(mockGetCountry).not.toHaveBeenCalled()
    expect(mockGetPosRefundPolicies).not.toHaveBeenCalled()
    expect(mockGetSubscription).not.toHaveBeenCalled()
  })

  it('bounds POS refund policy mutation invalidation to the active tenant cache (.668-.669)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)
    let policyCalls = 0

    mockGetPosRefundPolicies.mockImplementation(async () => {
      policyCalls += 1
      return {
        ...POS_REFUND_POLICY_DEFAULTS,
        customer_return_expiry_days: 30 + policyCalls,
      }
    })

    queryClient.setQueryData(
      ['pos-refund-policies', 'company-1', 'tenant-B', 'company-1'],
      { marker: 'tenant-B-policies-preserved' },
    )
    queryClient.setQueryData(
      ['reservation-settings', 'company-1', 'tenant-B', 'company-1'],
      { marker: 'tenant-B-reservation-preserved' },
    )

    const { result: reads } = renderHook(() => usePosRefundPolicies(), { wrapper })
    await waitFor(() => {
      expect(reads.current.isSuccess).toBe(true)
      expect(policyCalls).toBe(1)
    })

    const { result: mutation } = renderHook(() => useUpdatePosRefundPolicies(), { wrapper })
    await act(async () => {
      await mutation.current.mutateAsync({ customer_return_expiry_days: 45 })
    })

    await waitFor(() => {
      expect(policyCalls).toBe(2)
    })
    expect(queryClient.getQueryData([
      'pos-refund-policies',
      'company-1',
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-policies-preserved' })
    expect(queryClient.getQueryData([
      'reservation-settings',
      'company-1',
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-reservation-preserved' })
  })
})
