import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { waitFor } from '@testing-library/react'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'

import {
  uomCategoriesInvalidationPredicate,
  uomKeys,
  uomUnitsInvalidationPredicate,
  useCategories,
  useCreateUnit,
  useDeleteUnit,
  useUnits,
  useUpdateUnit,
} from '../hooks/useUnits'

// ─── API mock ────────────────────────────────────────────────────────────────

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiPut = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    apiGet: mockApiGet,
    apiPost: mockApiPost,
    apiPut: mockApiPut,
    apiDelete: mockApiDelete,
  }
})

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

function uomKeysFromCache(client: ReturnType<typeof createTestQueryClient>): unknown[][] {
  return client
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
    .filter((k) => Array.isArray(k) && k[0] === 'uom')
}

beforeEach(() => {
  mockApiGet.mockReset()
  mockApiGet.mockResolvedValue([])
  mockApiPost.mockReset()
  mockApiPost.mockResolvedValue({})
  mockApiPut.mockReset()
  mockApiPut.mockResolvedValue({})
  mockApiDelete.mockReset()
  mockApiDelete.mockResolvedValue(undefined)
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

// ─── uomKeys factory contract ────────────────────────────────────────────────

describe('uomKeys factory contract (wrap-at-callsite invariant)', () => {
  it('returns un-scoped structural prefixes', () => {
    expect(uomKeys.all).toEqual(['uom'])
    expect(uomKeys.categories()).toEqual(['uom', 'categories'])
    expect(uomKeys.units()).toEqual(['uom', 'units'])
    expect(uomKeys.unitsByCategory()).toEqual(['uom', 'units', { categoryId: undefined }])
    expect(uomKeys.unitsByCategory('cat-1')).toEqual(['uom', 'units', { categoryId: 'cat-1' }])
  })
})

// ─── useQuery shape probes (callsites .740, .741) ────────────────────────────

describe('useCategories / useUnits queryKey shapes', () => {
  function CategoriesProbe() {
    useCategories()
    return null
  }
  function UnitsProbe() {
    useUnits('cat-1')
    return null
  }

  it('useCategories queryKey carries tenant + company at the suffix (.740)', () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CategoriesProbe />, { queryClient })
    const keys = uomKeysFromCache(queryClient)
    const cat = keys.find((k) => k[1] === 'categories')
    expect(cat).toEqual(['uom', 'categories', 'tenant-A', 'company-1'])
  })

  it('useUnits(categoryId) queryKey carries tenant + company at the suffix (.741)', () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<UnitsProbe />, { queryClient })
    const keys = uomKeysFromCache(queryClient)
    const units = keys.find((k) => k[1] === 'units')
    expect(units).toEqual(['uom', 'units', { categoryId: 'cat-1' }, 'tenant-A', 'company-1'])
  })

  it('queryKeys differ across tenants', () => {
    setTenant('tenant-A', 'company-1')
    const cA = createTestQueryClient()
    renderWithProviders(<CategoriesProbe />, { queryClient: cA })
    const kA = JSON.stringify(uomKeysFromCache(cA))

    setTenant('tenant-B', 'company-1')
    const cB = createTestQueryClient()
    renderWithProviders(<CategoriesProbe />, { queryClient: cB })
    const kB = JSON.stringify(uomKeysFromCache(cB))

    expect(kA).toContain('tenant-A')
    expect(kB).toContain('tenant-B')
    expect(kA).not.toEqual(kB)
  })
})

// ─── Predicate unit tests ────────────────────────────────────────────────────

describe('uomUnitsInvalidationPredicate (callsites .742, .744, .746)', () => {
  it('matches ANY [uom, units, ...] query for the given t/c', () => {
    const pred = uomUnitsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['uom', 'units', { categoryId: 'cat-1' }, 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['uom', 'units', { categoryId: undefined }, 'tenant-A', 'company-1'] })).toBe(true)
  })

  it('rejects non-units queries (categories, products) and wrong tenant/company', () => {
    const pred = uomUnitsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['uom', 'categories', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['uom', 'units', null, 'tenant-B', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['uom', 'units', null, 'tenant-A', 'company-2'] })).toBe(false)
    expect(pred({ queryKey: ['products', 'list', null, 'tenant-A', 'company-1'] })).toBe(false)
  })
})

describe('uomCategoriesInvalidationPredicate (callsites .743, .745, .747)', () => {
  it('matches the [uom, categories, ...] tenant-scoped key', () => {
    const pred = uomCategoriesInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['uom', 'categories', 'tenant-A', 'company-1'] })).toBe(true)
  })

  it('rejects [uom, units, ...] and wrong tenant/company', () => {
    const pred = uomCategoriesInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['uom', 'units', null, 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['uom', 'categories', 'tenant-B', 'company-1'] })).toBe(false)
  })
})

// ─── Cascade tests for the 3 mutations (callsites .742-.747) ─────────────────
// These tests use per-call counters in queryFn so an always-false predicate
// would leave the counters at 1, deterministically failing the test (lesson
// from batch 3 round-1 F1).

describe('uom mutation cascades — fetch-count signals', () => {
  function CascadeProbe() {
    let categoriesCalls = 0
    let unitsCalls = 0
    ;(globalThis as Record<string, unknown>)['__uomCounters'] = { c: () => categoriesCalls, u: () => unitsCalls }
    mockApiGet.mockImplementation(async (url: string) => {
      if (url === '/uom/categories') {
        categoriesCalls += 1
        return [{ id: `cat-${categoriesCalls}`, name: `c-${categoriesCalls}`, code: 'C' }]
      }
      if (url.startsWith('/uom/units')) {
        unitsCalls += 1
        return [{ id: `unit-${unitsCalls}` }]
      }
      return []
    })
    useCategories()
    useUnits('cat-1')
    const create = useCreateUnit()
    const update = useUpdateUnit()
    const del = useDeleteUnit()
    ;(globalThis as Record<string, unknown>)['__uomMutations'] = { create, update, del }
    return null
  }

  function getCounters() {
    return (globalThis as Record<string, unknown>)['__uomCounters'] as { c: () => number; u: () => number }
  }
  function getMutations() {
    return (globalThis as Record<string, unknown>)['__uomMutations'] as {
      create: { mutateAsync: (input: unknown) => Promise<unknown> }
      update: { mutateAsync: (input: unknown) => Promise<unknown> }
      del: { mutateAsync: (input: unknown) => Promise<unknown> }
    }
  }

  it('useCreateUnit refetches BOTH units + categories (.742, .743)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().c()).toBe(1)
      expect(getCounters().u()).toBe(1)
    })

    await getMutations().create.mutateAsync({ categoryId: 'cat-1', code: 'X', name: 'X' })

    // Both units + categories caches refetched after invalidate cascade.
    expect(getCounters().c()).toBe(2)
    expect(getCounters().u()).toBe(2)
  })

  it('useUpdateUnit refetches BOTH units + categories (.744, .745)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().c()).toBe(1)
      expect(getCounters().u()).toBe(1)
    })

    await getMutations().update.mutateAsync({ id: 'u-1', input: { name: 'New' } })

    expect(getCounters().c()).toBe(2)
    expect(getCounters().u()).toBe(2)
  })

  it('useDeleteUnit refetches BOTH units + categories (.746, .747)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().c()).toBe(1)
      expect(getCounters().u()).toBe(1)
    })

    await getMutations().del.mutateAsync('u-1')

    expect(getCounters().c()).toBe(2)
    expect(getCounters().u()).toBe(2)
  })

  it('cross-tenant isolation: tenant-A mutation does not refetch tenant-B uom queries', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().c()).toBe(1)
      expect(getCounters().u()).toBe(1)
    })

    // Seed tenant-B units cache entry directly (no observer, but predicate
    // should reject it on the tail-segment match).
    const tenantBKey = ['uom', 'units', { categoryId: 'cat-1' }, 'tenant-B', 'company-1']
    queryClient.setQueryData(tenantBKey, [{ id: 'unit-tenant-b' }])

    await getMutations().create.mutateAsync({ categoryId: 'cat-1', code: 'X', name: 'X' })

    // Tenant-A counters advanced (cascade fired).
    expect(getCounters().c()).toBe(2)
    expect(getCounters().u()).toBe(2)
    // Tenant-B cache untouched.
    const tBQuery = queryClient.getQueryCache().find({ queryKey: tenantBKey, exact: true })
    expect(tBQuery?.state.data).toEqual([{ id: 'unit-tenant-b' }])
    expect(tBQuery?.state.isInvalidated).toBe(false)
  })
})
