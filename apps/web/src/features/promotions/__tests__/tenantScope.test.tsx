import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { waitFor } from '@testing-library/react'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'

import {
  PROMOTIONS_KEY,
  promotionsInvalidationPredicate,
  useActivatePromotion,
  useArchivePromotion,
  useCreatePromotion,
  useDeletePromotion,
  usePausePromotion,
  usePromotion,
  usePromotions,
  useUpdatePromotion,
} from '../hooks/usePromotions'

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

// Toast i18n init can pull in heavy modules; stub it.
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

function promotionKeysFromCache(client: ReturnType<typeof createTestQueryClient>): unknown[][] {
  return client
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
    .filter((k) => Array.isArray(k) && k[0] === 'promotions')
}

beforeEach(() => {
  mockApiGet.mockReset()
  mockApiGet.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } })
  mockApiPost.mockReset()
  mockApiPost.mockResolvedValue({ id: 'p-1' })
  mockApiPatch.mockReset()
  mockApiPatch.mockResolvedValue({ id: 'p-1' })
  mockApiDelete.mockReset()
  mockApiDelete.mockResolvedValue(undefined)
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

// ─── PROMOTIONS_KEY identifier contract ──────────────────────────────────────

describe('PROMOTIONS_KEY identifier contract (wrap-at-callsite invariant)', () => {
  it('is the un-scoped [promotions] structural prefix', () => {
    expect(PROMOTIONS_KEY).toEqual(['promotions'])
  })
})

// ─── Predicate unit tests ────────────────────────────────────────────────────

describe('promotionsInvalidationPredicate', () => {
  it('matches list + detail leaf keys for the given t/c', () => {
    const pred = promotionsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['promotions', {}, 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['promotions', { search: 'x' }, 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['promotions', 'p-123', 'tenant-A', 'company-1'] })).toBe(true)
  })

  it('rejects non-promotions namespaces and wrong tenant/company', () => {
    const pred = promotionsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['promotions', {}, 'tenant-B', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['promotions', {}, 'tenant-A', 'company-2'] })).toBe(false)
    expect(pred({ queryKey: ['products', 'list', null, 'tenant-A', 'company-1'] })).toBe(false)
  })

  it('rejects degenerate keys with fewer than 3 elements', () => {
    const pred = promotionsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['promotions'] })).toBe(false)
    expect(pred({ queryKey: ['promotions', 'tenant-A'] })).toBe(false)
  })
})

// ─── useQuery shape probes (callsites .573, .574) ────────────────────────────

describe('usePromotions / usePromotion queryKey shapes', () => {
  function ListProbe() {
    usePromotions()
    return null
  }
  function DetailProbe() {
    usePromotion('p-123')
    return null
  }

  it('usePromotions queryKey carries tenant + company at the suffix (.573)', () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<ListProbe />, { queryClient })
    const keys = promotionKeysFromCache(queryClient)
    // params={} default → [promotions, {}, tenant, company]
    const list = keys.find((k) => k.length === 4 && typeof k[1] === 'object')
    expect(list).toEqual(['promotions', {}, 'tenant-A', 'company-1'])
  })

  it('usePromotion(id) queryKey carries tenant + company at the suffix (.574)', () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<DetailProbe />, { queryClient })
    const keys = promotionKeysFromCache(queryClient)
    const detail = keys.find((k) => k[1] === 'p-123')
    expect(detail).toEqual(['promotions', 'p-123', 'tenant-A', 'company-1'])
  })

  it('queryKeys differ across tenants', () => {
    setTenant('tenant-A', 'company-1')
    const cA = createTestQueryClient()
    renderWithProviders(<ListProbe />, { queryClient: cA })
    const kA = JSON.stringify(promotionKeysFromCache(cA))

    setTenant('tenant-B', 'company-1')
    const cB = createTestQueryClient()
    renderWithProviders(<ListProbe />, { queryClient: cB })
    const kB = JSON.stringify(promotionKeysFromCache(cB))

    expect(kA).toContain('tenant-A')
    expect(kB).toContain('tenant-B')
    expect(kA).not.toEqual(kB)
  })
})

// ─── Cascade tests for the 6 mutations (callsites .575-.580) ─────────────────
// Per-call counters in queryFn so an always-false predicate would leave the
// counters at 1, deterministically failing the test (lesson from batch 3
// round-1 F1).

interface CascadeMutations {
  create: { mutateAsync: (input: unknown) => Promise<unknown> }
  update: { mutateAsync: (input: unknown) => Promise<unknown> }
  del: { mutateAsync: (input: unknown) => Promise<unknown> }
  activate: { mutateAsync: (input: unknown) => Promise<unknown> }
  pause: { mutateAsync: (input: unknown) => Promise<unknown> }
  archive: { mutateAsync: (input: unknown) => Promise<unknown> }
}

interface CascadeCounters {
  list: () => number
  detail: () => number
}

describe('promotion mutation cascades — fetch-count signals', () => {
  function CascadeProbe() {
    let listCalls = 0
    let detailCalls = 0
    ;(globalThis as Record<string, unknown>)['__promoCounters'] = {
      list: () => listCalls,
      detail: () => detailCalls,
    } satisfies CascadeCounters
    mockApiGet.mockImplementation(async (url: string) => {
      if (url === '/promotions' || url.startsWith('/promotions?')) {
        listCalls += 1
        return {
          data: [{ id: `p-${listCalls}` }],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        }
      }
      if (url.startsWith('/promotions/')) {
        detailCalls += 1
        return { id: `p-detail-${detailCalls}` }
      }
      return {}
    })
    usePromotions()
    usePromotion('p-123')
    const create = useCreatePromotion()
    const update = useUpdatePromotion()
    const del = useDeletePromotion()
    const activate = useActivatePromotion()
    const pause = usePausePromotion()
    const archive = useArchivePromotion()
    ;(globalThis as Record<string, unknown>)['__promoMutations'] = {
      create,
      update,
      del,
      activate,
      pause,
      archive,
    }
    return null
  }

  function getCounters(): CascadeCounters {
    return (globalThis as Record<string, unknown>)['__promoCounters'] as CascadeCounters
  }
  function getMutations(): CascadeMutations {
    return (globalThis as Record<string, unknown>)['__promoMutations'] as CascadeMutations
  }

  it('useCreatePromotion refetches BOTH list + detail (.575)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().list()).toBe(1)
      expect(getCounters().detail()).toBe(1)
    })

    await getMutations().create.mutateAsync({ name: 'X', type: 'percentage', discount_type: 'percentage', discount_value: '10', applies_to: 'all' })

    expect(getCounters().list()).toBe(2)
    expect(getCounters().detail()).toBe(2)
  })

  it('useUpdatePromotion refetches BOTH list + detail (.576)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().list()).toBe(1)
      expect(getCounters().detail()).toBe(1)
    })

    await getMutations().update.mutateAsync({ id: 'p-1', data: { name: 'Renamed' } })

    expect(getCounters().list()).toBe(2)
    expect(getCounters().detail()).toBe(2)
  })

  it('useDeletePromotion refetches BOTH list + detail (.577)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().list()).toBe(1)
      expect(getCounters().detail()).toBe(1)
    })

    await getMutations().del.mutateAsync('p-1')

    expect(getCounters().list()).toBe(2)
    expect(getCounters().detail()).toBe(2)
  })

  it('useActivatePromotion refetches BOTH list + detail (.578)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().list()).toBe(1)
      expect(getCounters().detail()).toBe(1)
    })

    await getMutations().activate.mutateAsync('p-1')

    expect(getCounters().list()).toBe(2)
    expect(getCounters().detail()).toBe(2)
  })

  it('usePausePromotion refetches BOTH list + detail (.579)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().list()).toBe(1)
      expect(getCounters().detail()).toBe(1)
    })

    await getMutations().pause.mutateAsync('p-1')

    expect(getCounters().list()).toBe(2)
    expect(getCounters().detail()).toBe(2)
  })

  it('useArchivePromotion refetches BOTH list + detail (.580)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().list()).toBe(1)
      expect(getCounters().detail()).toBe(1)
    })

    await getMutations().archive.mutateAsync('p-1')

    expect(getCounters().list()).toBe(2)
    expect(getCounters().detail()).toBe(2)
  })

  it('cross-tenant isolation: tenant-A mutation does not refetch tenant-B promotions queries', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().list()).toBe(1)
      expect(getCounters().detail()).toBe(1)
    })

    // Seed tenant-B detail cache entry directly; predicate should reject
    // it on the tail-segment match.
    const tenantBKey = ['promotions', 'p-other', 'tenant-B', 'company-1']
    queryClient.setQueryData(tenantBKey, { id: 'p-tenant-b' })

    await getMutations().create.mutateAsync({ name: 'X', type: 'percentage', discount_type: 'percentage', discount_value: '10', applies_to: 'all' })

    // Tenant-A counters advanced (cascade fired).
    expect(getCounters().list()).toBe(2)
    expect(getCounters().detail()).toBe(2)
    // Tenant-B cache untouched.
    const tBQuery = queryClient.getQueryCache().find({ queryKey: tenantBKey, exact: true })
    expect(tBQuery?.state.data).toEqual({ id: 'p-tenant-b' })
    expect(tBQuery?.state.isInvalidated).toBe(false)
  })
})
