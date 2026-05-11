import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'

import {
  useCashierPerformance,
  useCustomerAnalytics,
  useDiscountAnalysis,
  useFnbMetrics,
  useSalesByCategory,
  useSalesByPeriod,
  useSalesByProduct,
  useSalesSummary,
} from '../useAnalytics'
import type { AnalyticsFilters } from '../../api/analyticsApi'

const mockAnalyticsApi = vi.hoisted(() => ({
  fetchSalesSummary: vi.fn(),
  fetchSalesByCategory: vi.fn(),
  fetchSalesByProduct: vi.fn(),
  fetchSalesByPeriod: vi.fn(),
  fetchCashierPerformance: vi.fn(),
  fetchDiscountAnalysis: vi.fn(),
  fetchCustomerAnalytics: vi.fn(),
  fetchFnbMetrics: vi.fn(),
}))

vi.mock('../../api/analyticsApi', async () => {
  const actual = await vi.importActual<typeof import('../../api/analyticsApi')>('../../api/analyticsApi')
  return {
    ...actual,
    ...mockAnalyticsApi,
  }
})

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.com',
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
    companies: [
      {
        id: companyId,
        name: 'Test Company',
        legalName: 'Test Company LLC',
        taxId: null,
        countryCode: 'TN',
        currency: 'TND',
        locale: 'en_US',
        timezone: 'Africa/Tunis',
      },
    ],
    isLoading: false,
  })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function posAnalyticsKeysFromCache(client: ReturnType<typeof createTestQueryClient>): unknown[][] {
  return client
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
    .filter((k) => Array.isArray(k) && k[0] === 'pos' && k[1] === 'analytics')
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockAnalyticsApi.fetchSalesSummary.mockResolvedValue({})
  mockAnalyticsApi.fetchSalesByCategory.mockResolvedValue([])
  mockAnalyticsApi.fetchSalesByProduct.mockResolvedValue([])
  mockAnalyticsApi.fetchSalesByPeriod.mockResolvedValue([])
  mockAnalyticsApi.fetchCashierPerformance.mockResolvedValue([])
  mockAnalyticsApi.fetchDiscountAnalysis.mockResolvedValue({})
  mockAnalyticsApi.fetchCustomerAnalytics.mockResolvedValue({})
  mockAnalyticsApi.fetchFnbMetrics.mockResolvedValue({})
})

afterEach(() => {
  resetTenant()
})

describe('POS analytics tenant-scoped query keys', () => {
  const filters: AnalyticsFilters = { from: '2026-05-01', to: '2026-05-11' }

  function AnalyticsProbe() {
    useSalesSummary(filters)
    useSalesByCategory(filters)
    useSalesByProduct(filters)
    useSalesByPeriod(filters, 'week')
    useCashierPerformance(filters)
    useDiscountAnalysis(filters)
    useCustomerAnalytics(filters)
    useFnbMetrics(filters)
    return null
  }

  it('scopes every analytics query key at the tenant/company suffix (.465-.472)', () => {
    const queryClient = createTestQueryClient()

    renderWithProviders(<AnalyticsProbe />, { queryClient })

    expect(posAnalyticsKeysFromCache(queryClient)).toEqual(expect.arrayContaining([
      ['pos', 'analytics', 'summary', filters, 'tenant-A', 'company-1'],
      ['pos', 'analytics', 'sales-by-category', filters, 'tenant-A', 'company-1'],
      ['pos', 'analytics', 'sales-by-product', filters, 'tenant-A', 'company-1'],
      ['pos', 'analytics', 'sales-by-period', filters, 'week', 'tenant-A', 'company-1'],
      ['pos', 'analytics', 'cashiers', filters, 'tenant-A', 'company-1'],
      ['pos', 'analytics', 'discounts', filters, 'tenant-A', 'company-1'],
      ['pos', 'analytics', 'customers', filters, 'tenant-A', 'company-1'],
      ['pos', 'analytics', 'fnb', filters, 'tenant-A', 'company-1'],
    ]))
  })

  it('uses different cache slots across tenants', () => {
    setTenant('tenant-A', 'company-1')
    const clientA = createTestQueryClient()
    renderWithProviders(<AnalyticsProbe />, { queryClient: clientA })

    setTenant('tenant-B', 'company-1')
    const clientB = createTestQueryClient()
    renderWithProviders(<AnalyticsProbe />, { queryClient: clientB })

    expect(JSON.stringify(posAnalyticsKeysFromCache(clientA))).toContain('tenant-A')
    expect(JSON.stringify(posAnalyticsKeysFromCache(clientB))).toContain('tenant-B')
    expect(JSON.stringify(posAnalyticsKeysFromCache(clientA))).not.toEqual(
      JSON.stringify(posAnalyticsKeysFromCache(clientB)),
    )
  })

  it('does not fetch without tenant/company state even when dates are present', () => {
    resetTenant()
    renderWithProviders(<AnalyticsProbe />)

    expect(mockAnalyticsApi.fetchSalesSummary).not.toHaveBeenCalled()
    expect(mockAnalyticsApi.fetchSalesByCategory).not.toHaveBeenCalled()
    expect(mockAnalyticsApi.fetchSalesByProduct).not.toHaveBeenCalled()
    expect(mockAnalyticsApi.fetchSalesByPeriod).not.toHaveBeenCalled()
    expect(mockAnalyticsApi.fetchCashierPerformance).not.toHaveBeenCalled()
    expect(mockAnalyticsApi.fetchDiscountAnalysis).not.toHaveBeenCalled()
    expect(mockAnalyticsApi.fetchCustomerAnalytics).not.toHaveBeenCalled()
    expect(mockAnalyticsApi.fetchFnbMetrics).not.toHaveBeenCalled()
  })
})
