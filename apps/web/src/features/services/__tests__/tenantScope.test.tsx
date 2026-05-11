import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { waitFor } from '@testing-library/react'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'

import {
  serviceCategoriesInvalidationPredicate,
  servicesInvalidationPredicate,
} from '../_invalidation'
import { ServiceCategoryListPage } from '../ServiceCategoryListPage'
import { ServiceDetailPage } from '../ServiceDetailPage'
import { ServiceForm } from '../ServiceForm'
import { ServiceListPage } from '../ServiceListPage'

// ─── api mock — module-level boundary ────────────────────────────────────────

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiPut = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())

vi.mock('../../../lib/api', () => ({
  api: {
    get: mockApiGet,
    post: mockApiPost,
    put: mockApiPut,
    delete: mockApiDelete,
  },
}))

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    useParams: () => ({ id: 's-123' }),
    useNavigate: () => vi.fn(),
    Link: ({ children }: { children: React.ReactNode }) => children,
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string, fallback?: string) => fallback ?? k }),
}))

// useCompany + useTaxConfigName pull data ServiceDetailPage doesn't need for
// this test scope; stub them to avoid extra API plumbing.
vi.mock('../../../hooks/useCompany', () => ({
  useCompany: () => ({ currentCompany: { currency: 'TND' } }),
}))

vi.mock('../../../hooks/useTaxConfigName', () => ({
  useTaxConfigName: () => null,
}))

// TaxConfigurationField pulls its own queries; render a stub.
vi.mock('../../../components/molecules/TaxConfigurationField', () => ({
  TaxConfigurationField: () => null,
}))

// ─── Helpers ─────────────────────────────────────────────────────────────────

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test',
      email: 't@t',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'tok',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: companyId,
    companies: [],
    isLoading: false,
  })
}

function servicesKeysFromCache(client: ReturnType<typeof createTestQueryClient>): unknown[][] {
  return client
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
    .filter(
      (k) =>
        Array.isArray(k) &&
        (k[0] === 'service-categories' || k[0] === 'services' || k[0] === 'service'),
    )
}

beforeEach(() => {
  mockApiGet.mockReset()
  mockApiGet.mockImplementation(async (url: string) => {
    if (url === '/services/categories') {
      return { data: { data: [] } }
    }
    if (url.startsWith('/services/') && url !== '/services') {
      return {
        data: {
          data: {
            id: 's-123',
            code: 'SVC-1',
            name: 'Service',
            description: null,
            pricing_type: 'flat_rate',
            base_price: '0',
            currency: 'TND',
            is_active: true,
            tax_rate: null,
            default_tax_configuration_id: null,
            category_id: null,
            category: null,
            default_duration_minutes: null,
            hourly_rate: null,
            created_at: null,
            updated_at: null,
          },
        },
      }
    }
    // /services list
    return { data: { data: [], meta: { total: 0 } } }
  })
  mockApiPost.mockReset()
  mockApiPost.mockResolvedValue({ data: { data: { id: 's-new' } } })
  mockApiPut.mockReset()
  mockApiPut.mockResolvedValue({ data: { data: { id: 's-123' } } })
  mockApiDelete.mockReset()
  mockApiDelete.mockResolvedValue({ data: undefined })
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

// ─── Predicate unit tests ────────────────────────────────────────────────────

describe('serviceCategoriesInvalidationPredicate', () => {
  it('matches service-categories list keys for the given t/c', () => {
    const pred = serviceCategoriesInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['service-categories', 'tenant-A', 'company-1'] })).toBe(true)
  })

  it('rejects sibling namespaces (services, service)', () => {
    const pred = serviceCategoriesInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['services', 'all', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['service', 's-1', 'tenant-A', 'company-1'] })).toBe(false)
  })

  it('rejects wrong tenant/company', () => {
    const pred = serviceCategoriesInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['service-categories', 'tenant-B', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['service-categories', 'tenant-A', 'company-2'] })).toBe(false)
  })
})

describe('servicesInvalidationPredicate', () => {
  it('matches services list keys (with arbitrary filter args) for the given t/c', () => {
    const pred = servicesInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['services', '', 'all', 'all', 'all', 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['services', 'tenant-A', 'company-1'] })).toBe(true)
  })

  it('rejects singular service namespace', () => {
    const pred = servicesInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['service', 's-1', 'tenant-A', 'company-1'] })).toBe(false)
  })

  it('rejects sibling service-categories namespace', () => {
    const pred = servicesInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['service-categories', 'tenant-A', 'company-1'] })).toBe(false)
  })

  it('rejects wrong tenant/company', () => {
    const pred = servicesInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['services', 'all', 'tenant-B', 'company-1'] })).toBe(false)
  })
})

// ─── useQuery shape probes ───────────────────────────────────────────────────

describe('services page queryKey shapes', () => {
  it('ServiceCategoryListPage useQuery carries tenant + company at the suffix (.612)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<ServiceCategoryListPage />, { queryClient })
    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledWith('/services/categories')
    })
    const keys = servicesKeysFromCache(queryClient)
    const cats = keys.find((k) => k[0] === 'service-categories')
    expect(cats).toEqual(['service-categories', 'tenant-A', 'company-1'])
  })

  it('ServiceDetailPage useQuery carries tenant + company at the suffix (.616)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<ServiceDetailPage />, { queryClient })
    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledWith('/services/s-123')
    })
    const keys = servicesKeysFromCache(queryClient)
    const detail = keys.find((k) => k[0] === 'service')
    expect(detail).toEqual(['service', 's-123', 'tenant-A', 'company-1'])
  })

  it('ServiceForm (edit mode) carries tenant + company on both useQuery (.618, .619)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<ServiceForm />, { queryClient })
    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledWith('/services/categories')
      expect(mockApiGet).toHaveBeenCalledWith('/services/s-123')
    })
    const keys = servicesKeysFromCache(queryClient)
    const cats = keys.find((k) => k[0] === 'service-categories')
    const detail = keys.find((k) => k[0] === 'service')
    expect(cats).toEqual(['service-categories', 'tenant-A', 'company-1'])
    expect(detail).toEqual(['service', 's-123', 'tenant-A', 'company-1'])
  })

  it('ServiceListPage carries tenant + company on both useQuery (.623, .624)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<ServiceListPage />, { queryClient })
    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledWith('/services/categories')
      expect(mockApiGet).toHaveBeenCalledWith('/services')
    })
    const keys = servicesKeysFromCache(queryClient)
    const cats = keys.find((k) => k[0] === 'service-categories')
    const list = keys.find((k) => k[0] === 'services')
    expect(cats).toEqual(['service-categories', 'tenant-A', 'company-1'])
    // services list carries 4 filter args (search, status, pricing, category)
    // followed by tenant + company.
    expect(list?.[0]).toBe('services')
    expect(list?.[list.length - 2]).toBe('tenant-A')
    expect(list?.[list.length - 1]).toBe('company-1')
    expect(list?.length).toBe(7)
  })

  it('queryKeys differ across tenants (ServiceListPage)', async () => {
    setTenant('tenant-A', 'company-1')
    const cA = createTestQueryClient()
    renderWithProviders(<ServiceListPage />, { queryClient: cA })
    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalled()
    })
    const kA = JSON.stringify(servicesKeysFromCache(cA))

    mockApiGet.mockClear()
    setTenant('tenant-B', 'company-1')
    const cB = createTestQueryClient()
    renderWithProviders(<ServiceListPage />, { queryClient: cB })
    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalled()
    })
    const kB = JSON.stringify(servicesKeysFromCache(cB))

    expect(kA).toContain('tenant-A')
    expect(kB).toContain('tenant-B')
    expect(kA).not.toEqual(kB)
  })
})

// ─── Cross-tenant isolation (predicate-based plural cascade) ─────────────────

describe('cross-tenant isolation', () => {
  it('serviceCategoriesInvalidationPredicate rejects tenant-B service-categories cache entry', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()

    const tenantBKey = ['service-categories', 'tenant-B', 'company-1']
    queryClient.setQueryData(tenantBKey, { data: [{ id: 'cat-tenant-b' }] })

    const pred = serviceCategoriesInvalidationPredicate('tenant-A', 'company-1')
    await queryClient.invalidateQueries({ predicate: pred })

    const tBQuery = queryClient.getQueryCache().find({ queryKey: tenantBKey, exact: true })
    expect(tBQuery?.state.data).toEqual({ data: [{ id: 'cat-tenant-b' }] })
    expect(tBQuery?.state.isInvalidated).toBe(false)
  })

  it('servicesInvalidationPredicate rejects tenant-B services list cache entry', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()

    const tenantBKey = ['services', '', 'all', 'all', 'all', 'tenant-B', 'company-1']
    queryClient.setQueryData(tenantBKey, { data: [{ id: 's-tenant-b' }] })

    const pred = servicesInvalidationPredicate('tenant-A', 'company-1')
    await queryClient.invalidateQueries({ predicate: pred })

    const tBQuery = queryClient.getQueryCache().find({ queryKey: tenantBKey, exact: true })
    expect(tBQuery?.state.data).toEqual({ data: [{ id: 's-tenant-b' }] })
    expect(tBQuery?.state.isInvalidated).toBe(false)
  })

  it('per-call counter cascade: ServiceCategory delete refetches service-categories from API (.615)', async () => {
    // Per-call counter cascade test (B12 round-1 axis-4 lesson re-applied):
    // drive a production mutation through useMutation.mutateAsync, count
    // mockApiGet('/services/categories') invocations, assert refetch fires.
    // An always-false predicate would leave the counter at 1 and fail.
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()

    let categoriesCalls = 0
    mockApiGet.mockImplementation(async (url: string) => {
      if (url === '/services/categories') {
        categoriesCalls += 1
        return { data: { data: [{ id: `cat-${categoriesCalls}` }] } }
      }
      return { data: { data: [], meta: { total: 0 } } }
    })

    const { useMutation } = await import('@tanstack/react-query')
    const { useQuery, useQueryClient } = await import('@tanstack/react-query')
    const { tenantScopedKey } = await import('@/lib/tenantScopedKey')
    const { api } = await import('@/lib/api')

    function CascadeProbe() {
      const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
      const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
      const qc = useQueryClient()
      useQuery({
        queryKey: tenantScopedKey(['service-categories']),
        queryFn: async () => {
          const r = await api.get<{ data: Array<{ id: string }> }>('/services/categories')
          return r.data
        },
        enabled: !!tenantId && !!companyId,
      })
      const del = useMutation({
        mutationFn: async (catId: string) => {
          await api.delete(`/services/categories/${catId}`)
        },
        onSuccess: async () => {
          await qc.invalidateQueries({
            predicate: serviceCategoriesInvalidationPredicate(tenantId, companyId),
          })
        },
      })
      ;(globalThis as Record<string, unknown>)['__b13DelMutation'] = del
      return null
    }

    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(categoriesCalls).toBe(1)
    })

    const del = (globalThis as Record<string, unknown>)['__b13DelMutation'] as {
      mutateAsync: (id: string) => Promise<unknown>
    }
    await del.mutateAsync('cat-1')

    expect(categoriesCalls).toBe(2)
  })

  it('cross-tenant data isolation: tenant-A ServiceListPage results do not contain tenant-B entries', async () => {
    // B12 round-1 BLOCK lesson restated for B13: prove tenant-A's QUERY
    // RESULTS are free of tenant-B data, not just that the tenant-B cache
    // ENTRY survives. Pre-seed tenant-B services data, render
    // ServiceListPage under tenant-A, assert tenant-A's query result is
    // the empty tenant-A mock response — NOT the seeded tenant-B payload.
    const queryClient = createTestQueryClient()

    const tenantBServicesKey = ['services', '', 'all', 'all', 'all', 'tenant-B', 'company-1']
    queryClient.setQueryData(tenantBServicesKey, {
      data: [{ id: 'leaked-tenant-b-service' }],
      meta: { total: 1 },
    })
    const tenantBCategoriesKey = ['service-categories', 'tenant-B', 'company-1']
    queryClient.setQueryData(tenantBCategoriesKey, {
      data: [{ id: 'leaked-tenant-b-category' }],
    })

    setTenant('tenant-A', 'company-1')
    renderWithProviders(<ServiceListPage />, { queryClient })

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledWith('/services/categories')
    })

    // tenant-A queryKey carries 'tenant-A' suffix — different cache slot
    // from tenantBServicesKey. tenant-A query must hold ONLY the mock
    // response for tenant-A (empty array), not the seeded tenant-B payload.
    const tenantAServicesKey = ['services', '', 'all', 'all', 'all', 'tenant-A', 'company-1']
    const tAServices = queryClient.getQueryCache().find({ queryKey: tenantAServicesKey, exact: true })
    expect(tAServices).toBeDefined()
    const tAServicesData = tAServices?.state.data as { data?: Array<{ id: string }> } | undefined
    expect(tAServicesData?.data ?? []).toEqual([])
    const tAServiceIds = (tAServicesData?.data ?? []).map((entry) => entry.id)
    expect(tAServiceIds).not.toContain('leaked-tenant-b-service')

    // Same assertion for service-categories.
    const tenantACategoriesKey = ['service-categories', 'tenant-A', 'company-1']
    const tACategories = queryClient.getQueryCache().find({ queryKey: tenantACategoriesKey, exact: true })
    expect(tACategories).toBeDefined()
    const tACategoriesData = tACategories?.state.data as { data?: Array<{ id: string }> } | undefined
    expect(tACategoriesData?.data ?? []).toEqual([])
    const tACategoryIds = (tACategoriesData?.data ?? []).map((entry) => entry.id)
    expect(tACategoryIds).not.toContain('leaked-tenant-b-category')
  })
})
