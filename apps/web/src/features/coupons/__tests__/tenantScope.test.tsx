import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { waitFor } from '@testing-library/react'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'

import {
  COUPONS_KEY,
  couponsInvalidationPredicate,
  useCoupon,
  useCoupons,
  useCreateCoupon,
  useDeleteCoupon,
  useReactivateCoupon,
  useRevokeCoupon,
  useUpdateCoupon,
} from '../hooks/useCoupons'

// ─── API mock ────────────────────────────────────────────────────────────────

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    apiGet: mockApiGet,
    apiPost: mockApiPost,
    apiPatch: mockApiPatch,
    apiDelete: mockApiDelete,
  }
})

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
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

function couponKeysFromCache(client: ReturnType<typeof createTestQueryClient>): unknown[][] {
  return client
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
    .filter((k) => Array.isArray(k) && k[0] === 'coupons')
}

beforeEach(() => {
  mockApiGet.mockReset()
  mockApiGet.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } })
  mockApiPost.mockReset()
  mockApiPost.mockResolvedValue({ id: 'c-1' })
  mockApiPatch.mockReset()
  mockApiPatch.mockResolvedValue({ id: 'c-1' })
  mockApiDelete.mockReset()
  mockApiDelete.mockResolvedValue(undefined)
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

// ─── COUPONS_KEY identifier contract ──────────────────────────────────────

describe('COUPONS_KEY identifier contract (wrap-at-callsite invariant)', () => {
  it('is the un-scoped [coupons] structural prefix', () => {
    expect(COUPONS_KEY).toEqual(['coupons'])
  })
})

// ─── Predicate unit tests ────────────────────────────────────────────────────

describe('couponsInvalidationPredicate', () => {
  it('matches list + detail leaf keys for the given t/c', () => {
    const pred = couponsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['coupons', {}, 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['coupons', { search: 'x' }, 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['coupons', 'c-123', 'tenant-A', 'company-1'] })).toBe(true)
  })

  it('rejects non-coupons namespaces and wrong tenant/company', () => {
    const pred = couponsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['coupons', {}, 'tenant-B', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['coupons', {}, 'tenant-A', 'company-2'] })).toBe(false)
    expect(pred({ queryKey: ['promotions', 'list', null, 'tenant-A', 'company-1'] })).toBe(false)
  })

  it('rejects degenerate keys with fewer than 3 elements', () => {
    const pred = couponsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['coupons'] })).toBe(false)
    expect(pred({ queryKey: ['coupons', 'tenant-A'] })).toBe(false)
  })
})

// ─── useQuery shape probes (callsites .120, .121) ────────────────────────────

describe('useCoupons / useCoupon queryKey shapes', () => {
  function ListProbe() {
    useCoupons()
    return null
  }
  function DetailProbe() {
    useCoupon('c-123')
    return null
  }

  it('useCoupons queryKey carries tenant + company at the suffix (.120)', () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<ListProbe />, { queryClient })
    const keys = couponKeysFromCache(queryClient)
    const list = keys.find((k) => k.length === 4 && typeof k[1] === 'object')
    expect(list).toEqual(['coupons', {}, 'tenant-A', 'company-1'])
  })

  it('useCoupon(id) queryKey carries tenant + company at the suffix (.121)', () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<DetailProbe />, { queryClient })
    const keys = couponKeysFromCache(queryClient)
    const detail = keys.find((k) => k[1] === 'c-123')
    expect(detail).toEqual(['coupons', 'c-123', 'tenant-A', 'company-1'])
  })

  it('queryKeys differ across tenants', () => {
    setTenant('tenant-A', 'company-1')
    const cA = createTestQueryClient()
    renderWithProviders(<ListProbe />, { queryClient: cA })
    const kA = JSON.stringify(couponKeysFromCache(cA))

    setTenant('tenant-B', 'company-1')
    const cB = createTestQueryClient()
    renderWithProviders(<ListProbe />, { queryClient: cB })
    const kB = JSON.stringify(couponKeysFromCache(cB))

    expect(kA).toContain('tenant-A')
    expect(kB).toContain('tenant-B')
    expect(kA).not.toEqual(kB)
  })
})

// ─── Cascade tests for the 5 mutations (callsites .122-.126) ─────────────────
// Per-call counters in queryFn so an always-false predicate would leave the
// counters at 1, deterministically failing the test.

describe('coupon mutation cascades — fetch-count signals', () => {
  function CascadeProbe() {
    let listCalls = 0
    let detailCalls = 0
    ;(globalThis as Record<string, unknown>)['__couponCounters'] = {
      list: () => listCalls,
      detail: () => detailCalls,
    }
    mockApiGet.mockImplementation(async (url: string) => {
      if (url === '/coupons' || url.startsWith('/coupons?')) {
        listCalls += 1
        return {
          data: [{ id: `c-${listCalls}` }],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        }
      }
      if (url.startsWith('/coupons/')) {
        detailCalls += 1
        return { id: `c-detail-${detailCalls}` }
      }
      return {}
    })
    useCoupons()
    useCoupon('c-123')
    const create = useCreateCoupon()
    const update = useUpdateCoupon()
    const del = useDeleteCoupon()
    const revoke = useRevokeCoupon()
    const reactivate = useReactivateCoupon()
    ;(globalThis as Record<string, unknown>)['__couponMutations'] = {
      create,
      update,
      del,
      revoke,
      reactivate,
    }
    return null
  }

  function getCounters() {
    return (globalThis as Record<string, unknown>)['__couponCounters'] as {
      list: () => number
      detail: () => number
    }
  }
  function getMutations() {
    return (globalThis as Record<string, unknown>)['__couponMutations'] as {
      create: { mutateAsync: (input: unknown) => Promise<unknown> }
      update: { mutateAsync: (input: unknown) => Promise<unknown> }
      del: { mutateAsync: (input: unknown) => Promise<unknown> }
      revoke: { mutateAsync: (input: unknown) => Promise<unknown> }
      reactivate: { mutateAsync: (input: unknown) => Promise<unknown> }
    }
  }

  it('useCreateCoupon refetches BOTH list + detail (.122)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().list()).toBe(1)
      expect(getCounters().detail()).toBe(1)
    })

    await getMutations().create.mutateAsync({ code: 'X', name: 'X', discount_type: 'percentage', discount_value: '10' })

    expect(getCounters().list()).toBe(2)
    expect(getCounters().detail()).toBe(2)
  })

  it('useUpdateCoupon refetches BOTH list + detail (.123)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().list()).toBe(1)
      expect(getCounters().detail()).toBe(1)
    })

    await getMutations().update.mutateAsync({ id: 'c-1', data: { name: 'Renamed' } })

    expect(getCounters().list()).toBe(2)
    expect(getCounters().detail()).toBe(2)
  })

  it('useDeleteCoupon refetches BOTH list + detail (.124)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().list()).toBe(1)
      expect(getCounters().detail()).toBe(1)
    })

    await getMutations().del.mutateAsync('c-1')

    expect(getCounters().list()).toBe(2)
    expect(getCounters().detail()).toBe(2)
  })

  it('useRevokeCoupon refetches BOTH list + detail (.125)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().list()).toBe(1)
      expect(getCounters().detail()).toBe(1)
    })

    await getMutations().revoke.mutateAsync('c-1')

    expect(getCounters().list()).toBe(2)
    expect(getCounters().detail()).toBe(2)
  })

  it('useReactivateCoupon refetches BOTH list + detail (.126)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().list()).toBe(1)
      expect(getCounters().detail()).toBe(1)
    })

    await getMutations().reactivate.mutateAsync('c-1')

    expect(getCounters().list()).toBe(2)
    expect(getCounters().detail()).toBe(2)
  })

  it('cross-tenant isolation: tenant-A mutation does not refetch tenant-B coupons queries', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().list()).toBe(1)
      expect(getCounters().detail()).toBe(1)
    })

    const tenantBKey = ['coupons', 'c-other', 'tenant-B', 'company-1']
    queryClient.setQueryData(tenantBKey, { id: 'c-tenant-b' })

    await getMutations().create.mutateAsync({ code: 'X', name: 'X', discount_type: 'percentage', discount_value: '10' })

    expect(getCounters().list()).toBe(2)
    expect(getCounters().detail()).toBe(2)
    const tBQuery = queryClient.getQueryCache().find({ queryKey: tenantBKey, exact: true })
    expect(tBQuery?.state.data).toEqual({ id: 'c-tenant-b' })
    expect(tBQuery?.state.isInvalidated).toBe(false)
  })
})
