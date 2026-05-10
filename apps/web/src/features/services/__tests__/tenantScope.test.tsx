import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { waitFor } from '@testing-library/react'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'

import {
  serviceCategoriesInvalidationPredicate,
  servicesInvalidationPredicate,
} from '../_invalidation'

// ─── api mock ───────────────────────────────────────────────────────────────

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiPut = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())

vi.mock('../../../lib/api', async () => {
  const actual = await vi.importActual<Record<string, unknown>>('../../../lib/api')
  return {
    ...actual,
    api: {
      get: (...args: unknown[]) => mockApiGet(...args),
      post: (...args: unknown[]) => mockApiPost(...args),
      put: (...args: unknown[]) => mockApiPut(...args),
      delete: (...args: unknown[]) => mockApiDelete(...args),
    },
  }
})

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

vi.mock('../../../hooks/useCompany', () => ({
  useCompany: () => ({ currentCompany: { id: 'company-1', name: 'Test', currency: 'TND' } }),
}))

vi.mock('../../../hooks/useTaxConfigName', () => ({
  useTaxConfigName: () => ({ data: null }),
}))

// ─── Helpers ──────────────────────────────────────────────────────────────────

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

beforeEach(() => {
  mockApiGet.mockReset()
  mockApiGet.mockResolvedValue({ data: { data: [], meta: {} } })
  mockApiPost.mockReset()
  mockApiPost.mockResolvedValue({ data: { data: { id: 's-1' } } })
  mockApiPut.mockReset()
  mockApiPut.mockResolvedValue({ data: { data: { id: 's-1' } } })
  mockApiDelete.mockReset()
  mockApiDelete.mockResolvedValue({ data: {} })
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

// ─── Predicate unit tests ────────────────────────────────────────────────────

describe('servicesInvalidationPredicate', () => {
  it('matches services list/detail leaf keys for the given t/c', () => {
    const pred = servicesInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['services', '', 'all', 'all', 'all', 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['services', 'tenant-A', 'company-1'] })).toBe(true)
  })

  it('rejects sibling namespaces and wrong t/c', () => {
    const pred = servicesInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['service', 's-1', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['service-categories', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['services', '', 'all', 'all', 'all', 'tenant-B', 'company-1'] })).toBe(false)
  })

  it('rejects degenerate keys with fewer than 3 elements', () => {
    const pred = servicesInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['services'] })).toBe(false)
    expect(pred({ queryKey: ['services', 'tenant-A'] })).toBe(false)
  })
})

describe('serviceCategoriesInvalidationPredicate', () => {
  it('matches service-categories keys for the given t/c', () => {
    const pred = serviceCategoriesInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['service-categories', 'tenant-A', 'company-1'] })).toBe(true)
  })

  it('rejects sibling namespaces (services + singular service)', () => {
    const pred = serviceCategoriesInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['services', '', 'all', 'all', 'all', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['service', 's-1', 'tenant-A', 'company-1'] })).toBe(false)
  })
})

// ─── Cross-tenant cache isolation ────────────────────────────────────────────
// Stronger assertion (B12 round-1 lesson): proves tenant-A predicate-based
// invalidate does NOT touch tenant-B cache entries — tenant-B data + state
// remain intact.

describe('cross-tenant cache isolation (predicate-based services cascade)', () => {
  it('predicate-based invalidate rejects tenant-B services + service-categories cache entries', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()

    const tenantBServicesKey = ['services', '', 'all', 'all', 'all', 'tenant-B', 'company-1']
    const tenantBCategoriesKey = ['service-categories', 'tenant-B', 'company-1']
    const tenantBDetailKey = ['service', 's-other', 'tenant-B', 'company-1']
    queryClient.setQueryData(tenantBServicesKey, { data: [{ id: 's-tenant-b' }] })
    queryClient.setQueryData(tenantBCategoriesKey, { data: [{ id: 'cat-tenant-b' }] })
    queryClient.setQueryData(tenantBDetailKey, { data: { id: 's-tenant-b-detail' } })

    await Promise.all([
      queryClient.invalidateQueries({
        predicate: servicesInvalidationPredicate('tenant-A', 'company-1'),
      }),
      queryClient.invalidateQueries({
        predicate: serviceCategoriesInvalidationPredicate('tenant-A', 'company-1'),
      }),
    ])

    // tenant-B services intact.
    const tBServices = queryClient.getQueryCache().find({ queryKey: tenantBServicesKey, exact: true })
    expect(tBServices?.state.isInvalidated).toBe(false)
    // tenant-B service-categories intact.
    const tBCategories = queryClient.getQueryCache().find({ queryKey: tenantBCategoriesKey, exact: true })
    expect(tBCategories?.state.isInvalidated).toBe(false)
    // tenant-B singular detail (different namespace) — both predicates miss it.
    const tBDetail = queryClient.getQueryCache().find({ queryKey: tenantBDetailKey, exact: true })
    expect(tBDetail?.state.isInvalidated).toBe(false)
  })

  it('cross-tenant data isolation: tenant-A query result does not contain tenant-B services entries', async () => {
    // B12 round-1 stricter assertion shape applied from the start:
    // pre-seed tenant-B services data, render a tenant-A useQuery against
    // the same namespace, prove the tenant-A query returns the (empty)
    // tenant-A mock response — NOT the seeded tenant-B payload.
    const queryClient = createTestQueryClient()

    const tenantBServicesKey = ['services', '', 'all', 'all', 'all', 'tenant-B', 'company-1']
    queryClient.setQueryData(tenantBServicesKey, {
      data: [{ id: 'leaked-tenant-b-service' }],
    })

    setTenant('tenant-A', 'company-1')
    mockApiGet.mockResolvedValue({ data: { data: [] } })

    // Use the actual hook shape via a lightweight probe that mirrors
    // ServiceListPage's services useQuery.
    const { useQuery } = await import('@tanstack/react-query')
    const { tenantScopedKey } = await import('@/lib/tenantScopedKey')

    function ServicesProbe() {
      const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
      const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
      useQuery({
        queryKey: tenantScopedKey(['services', '', 'all', 'all', 'all']),
        queryFn: async () => {
          const r = await mockApiGet('/services')
          return r.data
        },
        enabled: !!tenantId && !!companyId,
      })
      return null
    }

    renderWithProviders(<ServicesProbe />, { queryClient })

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalled()
    })

    const tenantAKey = ['services', '', 'all', 'all', 'all', 'tenant-A', 'company-1']
    const tAQuery = queryClient.getQueryCache().find({ queryKey: tenantAKey, exact: true })
    expect(tAQuery).toBeDefined()
    const tAData = tAQuery?.state.data as { data?: Array<{ id: string }> } | undefined
    expect(tAData?.data ?? []).toEqual([])
    const tAIds = (tAData?.data ?? []).map((entry) => entry.id)
    expect(tAIds).not.toContain('leaked-tenant-b-service')
  })
})

// ─── Cascade tests via predicate-direct invalidate ───────────────────────────
// A lighter cascade form for B13: each mutation in the production code uses
// servicesInvalidationPredicate / serviceCategoriesInvalidationPredicate /
// exact-match wrap on `service`. The predicate unit tests above prove the
// gate logic; here we assert that running each predicate against a seeded
// cache invalidates the matching slot.

describe('cascade: predicate fires invalidation on the matching namespace slot', () => {
  it('servicesInvalidationPredicate invalidates [services, ...] slot only', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    const servicesKey = ['services', '', 'all', 'all', 'all', 'tenant-A', 'company-1']
    const categoriesKey = ['service-categories', 'tenant-A', 'company-1']
    queryClient.setQueryData(servicesKey, { data: [{ id: 's-1' }] })
    queryClient.setQueryData(categoriesKey, { data: [{ id: 'cat-1' }] })

    await queryClient.invalidateQueries({
      predicate: servicesInvalidationPredicate('tenant-A', 'company-1'),
    })

    const servicesQ = queryClient.getQueryCache().find({ queryKey: servicesKey, exact: true })
    expect(servicesQ?.state.isInvalidated).toBe(true)
    const categoriesQ = queryClient.getQueryCache().find({ queryKey: categoriesKey, exact: true })
    expect(categoriesQ?.state.isInvalidated).toBe(false)
  })

  it('serviceCategoriesInvalidationPredicate invalidates [service-categories, ...] slot only', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    const servicesKey = ['services', '', 'all', 'all', 'all', 'tenant-A', 'company-1']
    const categoriesKey = ['service-categories', 'tenant-A', 'company-1']
    queryClient.setQueryData(servicesKey, { data: [{ id: 's-1' }] })
    queryClient.setQueryData(categoriesKey, { data: [{ id: 'cat-1' }] })

    await queryClient.invalidateQueries({
      predicate: serviceCategoriesInvalidationPredicate('tenant-A', 'company-1'),
    })

    const categoriesQ = queryClient.getQueryCache().find({ queryKey: categoriesKey, exact: true })
    expect(categoriesQ?.state.isInvalidated).toBe(true)
    const servicesQ = queryClient.getQueryCache().find({ queryKey: servicesKey, exact: true })
    expect(servicesQ?.state.isInvalidated).toBe(false)
  })
})
